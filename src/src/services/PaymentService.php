<?php
/**
 * UniPortal – QR tuition payment service.
 *
 * Responsibilities:
 *   - create/reuse unique VietQR payment requests per tuition invoice;
 *   - verify and process provider webhooks idempotently;
 *   - apply full/partial/overpayments, flag unmatched transfers;
 *   - let Admin resolve exceptional transactions with audit logging.
 *
 * Money is only ever credited from a verified provider webhook or an explicit
 * Admin resolution of an unmatched transaction. Browser input is never trusted.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/VietQrService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AcademicAlertService.php';

class PaymentService {

    // ── Invoices & QR requests ──────────────────────────────────────────

    public static function confirmedTotal(PDO $pdo, int $tuitionId): int {
        $q = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_vnd), 0) FROM Payment
             WHERE tuition_id = :tid AND status = 'Confirmed'"
        );
        $q->execute([':tid' => $tuitionId]);
        return (int)$q->fetchColumn();
    }

    public static function expireStaleRequests(PDO $pdo, int $tuitionId): void {
        $pdo->prepare(
            "UPDATE PaymentRequest SET status='Expired'
             WHERE tuition_id = :tid AND status = 'Active' AND expires_at < NOW()"
        )->execute([':tid' => $tuitionId]);
    }

    /**
     * Return the active QR request for an invoice, creating one when needed.
     * Amount is always recomputed from confirmed payments on the server.
     * Returns null when the invoice is settled/waived or not owned by $studentId.
     */
    public static function ensureRequest(PDO $pdo, int $tuitionId, string $studentId,
        int $accountId = 0, bool $forceNew = false): ?array {
        $cfg = payment_config();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $q = $pdo->prepare(
                'SELECT t.*, sem.semester_name, sem.academic_year
                 FROM Tuition t JOIN Semester sem ON sem.semester_id = t.semester_id
                 WHERE t.tuition_id = :tid AND t.student_id = :sid LIMIT 1 FOR UPDATE'
            );
            $q->execute([':tid' => $tuitionId, ':sid' => $studentId]);
            $tuition = $q->fetch();
            if (!$tuition) {
                if ($ownTx) $pdo->rollBack();
                return null;
            }

            self::expireStaleRequests($pdo, $tuitionId);

            $amount      = (int)$tuition['amount_vnd'];
            $confirmed   = self::confirmedTotal($pdo, $tuitionId);
            $outstanding = max(0, $amount - $confirmed);
            // Legacy invoices can be marked Paid without Payment rows.
            if ($tuition['status'] === 'Waived' || $tuition['status'] === 'Paid' || $outstanding <= 0) {
                if ($ownTx) $pdo->commit();
                return null;
            }

            if (!$forceNew) {
                $q = $pdo->prepare(
                    "SELECT * FROM PaymentRequest
                     WHERE tuition_id = :tid AND status = 'Active'
                       AND amount_vnd = :amt AND expires_at > NOW()
                     ORDER BY payment_request_id DESC LIMIT 1"
                );
                $q->execute([':tid' => $tuitionId, ':amt' => $outstanding]);
                $existing = $q->fetch();
                if ($existing) {
                    if (empty($existing['qr_payload'])) {
                        $existing['qr_payload'] = VietQrService::buildPayload(
                            $existing['bank_bin'], $existing['account_number'],
                            (int)$existing['amount_vnd'], $existing['reference'], $existing['account_holder']
                        );
                        $pdo->prepare('UPDATE PaymentRequest SET qr_payload = ? WHERE payment_request_id = ?')
                            ->execute([$existing['qr_payload'], $existing['payment_request_id']]);
                    }
                    if ($ownTx) $pdo->commit();
                    return $existing;
                }
            }

            // Any older request for this invoice stops being payable.
            $pdo->prepare(
                "UPDATE PaymentRequest SET status='Expired'
                 WHERE tuition_id = :tid AND status = 'Active'"
            )->execute([':tid' => $tuitionId]);

            $expires = (new DateTimeImmutable('now'))
                ->modify('+' . $cfg['ttl_minutes'] . ' minutes')
                ->format('Y-m-d H:i:s');

            $request = null;
            for ($attempt = 0; $attempt < 6 && $request === null; $attempt++) {
                $reference = self::makeReference($studentId, (int)$tuition['semester_id']);
                $payload   = VietQrService::buildPayload(
                    $cfg['bank_bin'], $cfg['account_number'], $outstanding, $reference, $cfg['account_holder']
                );
                try {
                    $pdo->prepare(
                        "INSERT INTO PaymentRequest
                            (tuition_id, student_id, semester_id, amount_vnd, reference, provider,
                             bank_bin, bank_code, account_number, account_holder, qr_payload,
                             status, expires_at, created_by)
                         VALUES (:tid, :sid, :sem, :amt, :ref, :provider,
                                 :bin, :bcode, :acc, :holder, :payload,
                                 'Active', :exp, :by)"
                    )->execute([
                        ':tid' => $tuitionId, ':sid' => $studentId, ':sem' => (int)$tuition['semester_id'],
                        ':amt' => $outstanding, ':ref' => $reference, ':provider' => $cfg['provider'],
                        ':bin' => $cfg['bank_bin'], ':bcode' => $cfg['bank_code'],
                        ':acc' => $cfg['account_number'], ':holder' => $cfg['account_holder'],
                        ':payload' => $payload, ':exp' => $expires,
                        ':by' => $accountId > 0 ? $accountId : null,
                    ]);
                    $request = [
                        'payment_request_id' => (int)$pdo->lastInsertId(),
                        'tuition_id' => $tuitionId, 'student_id' => $studentId,
                        'semester_id' => (int)$tuition['semester_id'], 'amount_vnd' => $outstanding,
                        'reference' => $reference, 'provider' => $cfg['provider'],
                        'bank_bin' => $cfg['bank_bin'], 'bank_code' => $cfg['bank_code'],
                        'account_number' => $cfg['account_number'], 'account_holder' => $cfg['account_holder'],
                        'qr_payload' => $payload, 'status' => 'Active', 'expires_at' => $expires,
                    ];
                } catch (PDOException $e) {
                    // Reference collision – try another random suffix.
                    if ((string)$e->getCode() !== '23000') throw $e;
                }
            }
            if ($request === null) throw new RuntimeException('Không thể tạo mã thanh toán duy nhất.');

            if ($accountId > 0) {
                AcademicAlertService::writeAuditLog($pdo, $accountId, 'CREATE_PAYMENT_REQUEST',
                    'PaymentRequest', (string)$request['payment_request_id'], null,
                    json_encode(['tuition_id' => $tuitionId, 'amount' => $outstanding, 'reference' => $request['reference']]));
            }
            if ($ownTx) $pdo->commit();
            return $request;
        } catch (Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function makeReference(string $studentId, int $semesterId): string {
        $sid = preg_replace('/[^0-9A-Za-z]/', '', $studentId);
        return 'UNI' . $sid . 'HK' . $semesterId . strtoupper(bin2hex(random_bytes(3)));
    }

    /** Invoice + payment request + transaction state for the student UI/poller. */
    public static function summary(PDO $pdo, int $tuitionId, string $studentId, int $accountId = 0): ?array {
        $q = $pdo->prepare(
            'SELECT t.*, sem.semester_name, sem.academic_year
             FROM Tuition t JOIN Semester sem ON sem.semester_id = t.semester_id
             WHERE t.tuition_id = :tid AND t.student_id = :sid LIMIT 1'
        );
        $q->execute([':tid' => $tuitionId, ':sid' => $studentId]);
        $tuition = $q->fetch();
        if (!$tuition) return null;

        $confirmed = self::confirmedTotal($pdo, $tuitionId);
        $amount    = (int)$tuition['amount_vnd'];
        // Preserve the legacy "Paid" flag for invoices settled before QR payments existed.
        if ($tuition['status'] === 'Paid') $confirmed = max($confirmed, $amount);
        elseif ($tuition['status'] === 'Waived') $amount = 0;
        $confirmed   = min($confirmed, $amount);
        $outstanding = max(0, $amount - $confirmed);

        $pPending = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_vnd),0) FROM Payment
             WHERE tuition_id = :tid AND status = 'Pending'"
        );
        $pPending->execute([':tid' => $tuitionId]);
        $pendingLegacy = (int)$pPending->fetchColumn();

        $request = null;
        if ($outstanding > 0 && $tuition['status'] !== 'Waived') {
            try {
                $request = self::ensureRequest($pdo, $tuitionId, $studentId, $accountId);
            } catch (Throwable $e) {
                error_log('Payment request: ' . $e->getMessage());
            }
        }

        $p = $pdo->prepare(
            'SELECT p.*, bt.gateway, bt.reference_code, bt.transaction_date, bt.status AS bank_status
             FROM Payment p LEFT JOIN BankTransaction bt ON bt.payment_id = p.payment_id
             WHERE p.tuition_id = :tid ORDER BY p.created_at DESC, p.payment_id DESC'
        );
        $p->execute([':tid' => $tuitionId]);
        $payments = $p->fetchAll();

        $v = $pdo->prepare(
            "SELECT COUNT(*) FROM BankTransaction bt
             WHERE bt.status = 'Unmatched' AND (
                bt.payment_request_id IN (SELECT payment_request_id FROM PaymentRequest WHERE tuition_id = :tid)
                OR bt.content LIKE :sid OR bt.code LIKE :sid2)
            "
        );
        $v->execute([':tid' => $tuitionId, ':sid' => '%' . $studentId . '%', ':sid2' => '%' . $studentId . '%']);
        $verificationRequired = (int)$v->fetchColumn() > 0;

        $display = 'Unpaid';
        if ($tuition['status'] === 'Waived')        $display = 'Waived';
        elseif ($outstanding <= 0)                  $display = 'Paid';
        elseif ($verificationRequired)              $display = 'Review';
        elseif ($confirmed > 0)                     $display = 'Partial';
        elseif ($request !== null)                  $display = 'Awaiting';
        elseif ($pendingLegacy > 0)                 $display = 'Awaiting';

        return [
            'tuition' => $tuition, 'amount' => $amount, 'confirmed' => $confirmed,
            'outstanding' => $outstanding, 'pending_legacy' => $pendingLegacy,
            'request' => $request, 'payments' => $payments,
            'verification_required' => $verificationRequired, 'display_status' => $display,
        ];
    }

    // ── Webhook verification / processing ───────────────────────────────

    /** @return array{ok:bool,status:int,message?:string,test?:bool} */
    public static function authenticateWebhook(string $rawBody, array $headers): array {
        $cfg = payment_config();
        $h = [];
        foreach ($headers as $k => $v) $h[strtolower((string)$k)] = is_array($v) ? ($v[0] ?? '') : (string)$v;

        $authorization = $h['authorization'] ?? '';
        $presented = '';
        if (stripos($authorization, 'Apikey ') === 0) {
            $presented = trim(substr($authorization, 7));
        } elseif (stripos($authorization, 'Bearer ') === 0) {
            $presented = trim(substr($authorization, 7));
        }
        if ($presented === '' && isset($h['x-api-key'])) $presented = trim($h['x-api-key']);

        // Local test channel: only when PAYMENT_TEST_MODE=1 and a test key exists.
        if ($cfg['test_mode'] && $cfg['test_webhook_key'] !== '' && $presented !== ''
            && hash_equals($cfg['test_webhook_key'], $presented)) {
            return ['ok' => true, 'status' => 200, 'test' => true];
        }

        if (!$cfg['webhook_enabled']) {
            return ['ok' => false, 'status' => 503,
                'message' => 'Webhook thanh toán chưa được bật (payment_webhook_enabled=0).'];
        }
        if (!$cfg['live_ready']) {
            return ['ok' => false, 'status' => 503,
                'message' => 'Chưa cấu hình SEPAY_API_KEY/SEPAY_WEBHOOK_SECRET trong .env.'];
        }

        $mode = $cfg['auth_mode'];
        $apiOk = false;
        $hmacOk = false;

        if ($cfg['api_key'] !== '' && $presented !== '') {
            $apiOk = hash_equals($cfg['api_key'], $presented);
        }
        if ($cfg['webhook_secret'] !== '') {
            $ts = trim($h['x-sepay-timestamp'] ?? '');
            $sig = trim($h['x-sepay-signature'] ?? '');
            if ($ts !== '' && $sig !== '' && ctype_digit($ts)
                && abs(time() - (int)$ts) <= 300) {
                $expected = 'sha256=' . hash_hmac('sha256', $ts . '.' . $rawBody, $cfg['webhook_secret']);
                $hmacOk = hash_equals($expected, $sig);
            }
        }

        $ok = match ($mode) {
            'hmac'  => $hmacOk,
            'both'  => $apiOk && $hmacOk,
            default => $apiOk,
        };
        if (!$ok) {
            return ['ok' => false, 'status' => 401, 'message' => 'Xác thực webhook thất bại.'];
        }
        return ['ok' => true, 'status' => 200, 'test' => false];
    }

    /**
     * Process one provider webhook. Always returns a response the provider can
     * consume; money is credited only inside the verified branch.
     */
    public static function handleWebhook(string $rawBody, array $headers): array {
        $cfg  = payment_config();
        $auth = self::authenticateWebhook($rawBody, $headers);
        if (!$auth['ok']) {
            return ['status' => $auth['status'], 'body' => ['success' => false, 'message' => $auth['message']]];
        }
        $isTest = !empty($auth['test']);

        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return ['status' => 400, 'body' => ['success' => false, 'message' => 'JSON không hợp lệ.']];
        }

        $providerTxnId = trim((string)($data['id'] ?? ''));
        if ($providerTxnId === '') {
            return ['status' => 422, 'body' => ['success' => false, 'message' => 'Thiếu id giao dịch.']];
        }
        $transferType = (string)($data['transferType'] ?? 'unknown');
        if (!in_array($transferType, ['in', 'out'], true)) $transferType = 'unknown';

        $txn = [
            'provider' => $cfg['provider'],
            'provider_transaction_id' => mb_substr($providerTxnId, 0, 100),
            'gateway' => self::short((string)($data['gateway'] ?? ''), 100),
            'account_number' => self::short((string)($data['accountNumber'] ?? ''), 50),
            'sub_account' => self::short((string)($data['subAccount'] ?? ''), 50),
            'code' => self::short((string)($data['code'] ?? ''), 100),
            'content' => self::short((string)($data['content'] ?? ''), 500),
            'description' => self::short((string)($data['description'] ?? ''), 500),
            'transfer_type' => $transferType,
            'transfer_amount' => (int)($data['transferAmount'] ?? 0),
            'accumulated' => isset($data['accumulated']) ? (int)$data['accumulated'] : null,
            'reference_code' => self::short((string)($data['referenceCode'] ?? ''), 100),
            'transaction_date' => self::parseDate((string)($data['transactionDate'] ?? '')),
            'is_test' => $isTest ? 1 : 0,
            'raw_payload' => mb_substr($rawBody, 0, 60000),
        ];

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            try {
                $pdo->prepare(
                    "INSERT INTO BankTransaction
                        (provider, provider_transaction_id, gateway, account_number, sub_account,
                         code, content, description, transfer_type, transfer_amount, accumulated,
                         reference_code, transaction_date, status, is_test, raw_payload)
                     VALUES (:provider, :ptid, :gateway, :account, :sub, :code, :content, :desc,
                             :type, :amount, :acc, :refcode, :xdate, 'Unmatched', :test, :raw)"
                )->execute([
                    ':provider' => $txn['provider'], ':ptid' => $txn['provider_transaction_id'],
                    ':gateway' => $txn['gateway'], ':account' => $txn['account_number'],
                    ':sub' => $txn['sub_account'], ':code' => $txn['code'],
                    ':content' => $txn['content'], ':desc' => $txn['description'],
                    ':type' => $txn['transfer_type'], ':amount' => $txn['transfer_amount'],
                    ':acc' => $txn['accumulated'], ':refcode' => $txn['reference_code'],
                    ':xdate' => $txn['transaction_date'], ':test' => $txn['is_test'],
                    ':raw' => $txn['raw_payload'],
                ]);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') {
                    $pdo->rollBack();
                    // Keep the original review/processing status untouched.
                    $pdo->prepare(
                        "UPDATE BankTransaction SET match_note=COALESCE(match_note,'Webhook trùng – đã bỏ qua')
                         WHERE provider=:p AND provider_transaction_id=:id"
                    )->execute([':p' => $txn['provider'], ':id' => $txn['provider_transaction_id']]);
                    return ['status' => 200, 'body' => ['success' => true, 'duplicate' => true]];
                }
                throw $e;
            }
            $bankTxId = (int)$pdo->lastInsertId();

            $reject = function (string $status, string $note, ?int $requestId = null) use ($pdo, $bankTxId, $txn, $isTest): array {
                $pdo->prepare(
                    'UPDATE BankTransaction SET status=:st, match_note=:note, payment_request_id=:pr, processed_at=NOW()
                     WHERE bank_transaction_id=:id'
                )->execute([':st' => $status, ':note' => self::short($note, 500),
                    ':pr' => $requestId, ':id' => $bankTxId]);
                if (in_array($status, ['Unmatched', 'Rejected'], true)) {
                    self::notifyAdmins('Giao dịch cần kiểm tra: ' . number_format($txn['transfer_amount']) . ' ₫',
                        $note . ' – ' . ($txn['content'] ?: $txn['code']), $isTest);
                }
                AcademicAlertService::writeAuditLog($pdo, 0, 'BANK_TX_' . strtoupper($status),
                    'BankTransaction', (string)$bankTxId, null,
                    json_encode(['amount' => $txn['transfer_amount'], 'content' => $txn['content'],
                        'provider_id' => $txn['provider_transaction_id'], 'test' => $isTest], JSON_UNESCAPED_UNICODE));
                $pdo->commit();
                return ['status' => 200, 'body' => ['success' => true, 'status' => strtolower($status)]];
            };

            if (!self::accountMatches($cfg['account_number'], $txn['account_number'])) {
                return $reject('Rejected', 'Tài khoản nhận tiền không khớp cấu hình: ' . $txn['account_number']);
            }
            if ($txn['transfer_type'] !== 'in') {
                return $reject('Ignored', 'Bỏ qua giao dịch không phải tiền vào (' . $txn['transfer_type'] . ').');
            }
            if ($txn['transfer_amount'] <= 0) {
                return $reject('Rejected', 'Số tiền giao dịch không hợp lệ.');
            }
            if ($txn['transfer_amount'] < $cfg['min_transfer']) {
                return $reject('Unmatched', 'Số tiền thấp hơn mức tối thiểu ' . number_format($cfg['min_transfer']) . ' ₫.');
            }

            $request = self::findRequest($pdo, $txn);
            if (!$request) {
                return $reject('Unmatched', 'Không tìm thấy mã thanh toán trong nội dung chuyển khoản.');
            }
            $requestId = (int)$request['payment_request_id'];

            $q = $pdo->prepare('SELECT * FROM Tuition WHERE tuition_id = :tid LIMIT 1 FOR UPDATE');
            $q->execute([':tid' => (int)$request['tuition_id']]);
            $tuition = $q->fetch();
            if (!$tuition) {
                return $reject('Unmatched', 'Hóa đơn của mã ' . $request['reference'] . ' không tồn tại.', $requestId);
            }

            $confirmed   = self::confirmedTotal($pdo, (int)$tuition['tuition_id']);
            $outstanding = max(0, (int)$tuition['amount_vnd'] - $confirmed);
            if ($outstanding <= 0) {
                return $reject('Unmatched', 'Hóa đơn đã thanh toán đủ; khoản chuyển thêm cần Admin xử lý.', $requestId);
            }
            if (!$cfg['partial_allowed'] && $txn['transfer_amount'] < $outstanding) {
                return $reject('Unmatched', 'Chính sách không cho thanh toán một phần (' .
                    number_format($txn['transfer_amount']) . ' / ' . number_format($outstanding) . ' ₫).', $requestId);
            }

            $result = self::applyVerifiedPayment($pdo, $tuition, $request, $txn, $outstanding, 0);
            $pdo->prepare(
                'UPDATE BankTransaction SET status=:st, payment_request_id=:pr, payment_id=:pay,
                        match_note=:note, processed_at=NOW() WHERE bank_transaction_id=:id'
            )->execute([
                ':st' => $result['overpaid'] > 0 ? 'Processed' : 'Processed',
                ':pr' => $requestId, ':pay' => $result['payment_id'],
                ':note' => self::short($result['note'], 500), ':id' => $bankTxId,
            ]);
            $pdo->commit();
            return ['status' => 200, 'body' => ['success' => true, 'payment_id' => $result['payment_id'],
                'status' => $result['invoice_status'], 'overpaid' => $result['overpaid']]];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment webhook: ' . $e->getMessage());
            return ['status' => 500, 'body' => ['success' => false, 'message' => 'Không thể xử lý giao dịch.']];
        }
    }

    /**
     * Create the confirmed Payment row and update invoice/request state.
     * Caller must hold a transaction and have locked the Tuition row.
     */
    private static function applyVerifiedPayment(PDO $pdo, array $tuition, array $request,
        array $txn, int $outstanding, int $adminId): array {
        $amount    = (int)$txn['transfer_amount'];
        $overpaid  = max(0, $amount - $outstanding);
        $paidFull  = $amount >= $outstanding;
        $invoice   = $paidFull ? 'Paid' : 'Partial';
        $review    = $overpaid > 0 ? 'Review' : 'None';
        $confirmedAt = $txn['transaction_date'] ?: date('Y-m-d H:i:s');

        $pdo->prepare(
            "INSERT INTO Payment
                (tuition_id, payment_request_id, student_id, amount_vnd, method, provider,
                 provider_transaction_id, reference_no, bank_reference, note, status,
                 reviewed_by, reviewed_at, confirmed_at, is_test, overpaid_vnd, review_status)
             VALUES (:tid, :pr, :sid, :amt, 'BankTransfer', :provider,
                     :ptid, :ref, :bankref, :note, 'Confirmed',
                     :admin, :reviewed_at, :confirmed, :test, :over, :review)"
        )->execute([
            ':tid' => (int)$tuition['tuition_id'],
            ':pr' => !empty($request['payment_request_id']) ? (int)$request['payment_request_id'] : null,
            ':sid' => $tuition['student_id'], ':amt' => $amount, ':provider' => $txn['provider'],
            ':ptid' => $txn['provider_transaction_id'],
            ':ref' => self::short($txn['code'] ?: $request['reference'], 100),
            ':bankref' => self::short($txn['reference_code'], 100),
            ':note' => 'Xác nhận tự động từ ' . ($txn['gateway'] ?: 'ngân hàng')
                . (!empty($txn['is_test']) ? ' (TEST)' : ''),
            ':admin' => $adminId > 0 ? $adminId : null,
            ':reviewed_at' => $adminId > 0 ? date('Y-m-d H:i:s') : null,
            ':confirmed' => $confirmedAt, ':test' => (int)($txn['is_test'] ?? 0),
            ':over' => $overpaid, ':review' => $review,
        ]);
        $paymentId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE PaymentRequest SET status='Completed' WHERE payment_request_id = :id")
            ->execute([':id' => (int)$request['payment_request_id']]);
        $pdo->prepare("UPDATE Tuition SET status=:st WHERE tuition_id=:tid AND status<>'Waived'")
            ->execute([':st' => $invoice, ':tid' => (int)$tuition['tuition_id']]);

        $note = 'Khớp ' . $request['reference'] . ': nhận ' . number_format($amount)
            . ' ₫, còn nợ ' . number_format($outstanding) . ' ₫';
        if ($overpaid > 0) $note .= ' – vượt ' . number_format($overpaid) . ' ₫, cần Admin xử lý';

        $accountId = self::studentAccountId($pdo, (string)$tuition['student_id']);
        if ($accountId > 0) {
            NotificationService::send($accountId, 'Payment',
                $paidFull ? '✅ Đã xác nhận thanh toán học phí' : '💰 Đã nhận thanh toán một phần',
                ($paidFull ? 'Đã nhận ' : 'Đã nhận ') . number_format($amount) . ' ₫' . ($paidFull ? '' : ', còn lại ' . number_format($outstanding - $amount) . ' ₫')
                . ' • Mã GD ' . $txn['provider_transaction_id'] . (!empty($txn['is_test']) ? ' (TEST)' : ''),
                app_url('assets/student/tuition.php'));
        }
        if ($overpaid > 0) {
            self::notifyAdmins('Khoản thu vượt cần xử lý: +' . number_format($overpaid) . ' ₫',
                'Hóa đơn #' . $tuition['tuition_id'] . ' – ' . $request['reference'], !empty($txn['is_test']));
        }
        AcademicAlertService::writeAuditLog($pdo, $adminId > 0 ? $adminId : 0, 'PAYMENT_VERIFIED',
            'Payment', (string)$paymentId, null,
            json_encode(['tuition_id' => (int)$tuition['tuition_id'], 'amount' => $amount,
                'overpaid' => $overpaid, 'provider_txn' => $txn['provider_transaction_id'],
                'reference' => $request['reference'], 'test' => (int)($txn['is_test'] ?? 0)], JSON_UNESCAPED_UNICODE));

        return ['payment_id' => $paymentId, 'overpaid' => $overpaid,
            'invoice_status' => $invoice, 'note' => $note];
    }

    private static function findRequest(PDO $pdo, array $txn): ?array {
        $haystack = trim($txn['code'] . ' ' . $txn['content'] . ' ' . $txn['description'] . ' ' . $txn['sub_account']);
        $paidTs   = $txn['transaction_date'] ? (int)strtotime($txn['transaction_date']) : time();

        // A request is only payable until expires_at; late transfers must be
        // reviewed manually instead of being auto-credited.
        $payable = static function (array $row) use ($paidTs): bool {
            if ($row['status'] === 'Cancelled') return false;
            $expires = strtotime($row['expires_at'] ?? '');
            return $expires !== false && $paidTs <= $expires;
        };

        $reference = VietQrService::extractReference($haystack);
        if ($reference !== null) {
            $q = $pdo->prepare('SELECT * FROM PaymentRequest WHERE reference = :ref LIMIT 1');
            $q->execute([':ref' => $reference]);
            $row = $q->fetch();
            if ($row && $payable($row)) return $row;
        }
        $norm = VietQrService::normalize($haystack);
        $q = $pdo->query(
            "SELECT * FROM PaymentRequest
             WHERE status IN ('Active','Completed','Expired')
             ORDER BY payment_request_id DESC LIMIT 200"
        );
        foreach ($q->fetchAll() as $row) {
            if ($row['reference'] !== '' && strpos($norm, $row['reference']) !== false && $payable($row)) {
                return $row;
            }
        }
        return null;
    }

    public static function resolveUnmatched(int $bankTxId, int $tuitionId, int $adminId,
        string $note = ''): array {
        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('SELECT * FROM BankTransaction WHERE bank_transaction_id=:id LIMIT 1 FOR UPDATE');
            $q->execute([':id' => $bankTxId]);
            $bank = $q->fetch();
            if (!$bank) throw new RuntimeException('Không tìm thấy giao dịch.');
            if (!in_array($bank['status'], ['Unmatched', 'Rejected'], true)) {
                throw new RuntimeException('Giao dịch đã được xử lý trước đó.');
            }
            if ((int)$bank['transfer_amount'] <= 0) {
                throw new RuntimeException('Số tiền giao dịch không hợp lệ.');
            }

            $q = $pdo->prepare('SELECT * FROM Tuition WHERE tuition_id=:tid LIMIT 1 FOR UPDATE');
            $q->execute([':tid' => $tuitionId]);
            $tuition = $q->fetch();
            if (!$tuition) throw new RuntimeException('Không tìm thấy hóa đơn học phí.');

            $confirmed = self::confirmedTotal($pdo, $tuitionId);
            $outstanding = max(0, (int)$tuition['amount_vnd'] - $confirmed);
            if ($outstanding <= 0) throw new RuntimeException('Hóa đơn này đã được thanh toán đủ.');

            $request = null;
            if (!empty($bank['payment_request_id'])) {
                $q = $pdo->prepare('SELECT * FROM PaymentRequest WHERE payment_request_id=:id LIMIT 1');
                $q->execute([':id' => (int)$bank['payment_request_id']]);
                $request = $q->fetch() ?: null;
            }
            if (!$request) {
                // Verified manual resolution still needs a traceable reference.
                $request = [
                    'payment_request_id' => null,
                    'reference' => 'ADMIN-' . $bankTxId,
                ];
            }

            $txn = [
                'provider' => $bank['provider'],
                'provider_transaction_id' => $bank['provider_transaction_id'],
                'gateway' => $bank['gateway'],
                'code' => $bank['code'],
                'reference_code' => $bank['reference_code'],
                'transaction_date' => $bank['transaction_date'],
                'transfer_amount' => (int)$bank['transfer_amount'],
                'is_test' => (int)$bank['is_test'],
            ];
            $result = self::applyVerifiedPayment($pdo, $tuition, $request, $txn, $outstanding, $adminId);

            $pdo->prepare(
                "UPDATE BankTransaction SET status='Processed', payment_id=:pay, payment_request_id=:pr,
                        reviewed_by=:admin, reviewed_at=NOW(), processed_at=NOW(), match_note=:note
                 WHERE bank_transaction_id=:id"
            )->execute([
                ':pay' => $result['payment_id'],
                ':pr' => $request['payment_request_id'] ?: null,
                ':admin' => $adminId, ':note' => self::short($note !== '' ? $note : $result['note'], 500),
                ':id' => $bankTxId,
            ]);
            // The invoice is settled for this transaction; the original QR request (if any) is complete.
            if (!empty($request['payment_request_id'])) {
                $pdo->prepare("UPDATE PaymentRequest SET status='Completed' WHERE payment_request_id=:id")
                    ->execute([':id' => (int)$request['payment_request_id']]);
            }
            AcademicAlertService::writeAuditLog($pdo, $adminId, 'PAYMENT_REVIEW_RESOLVED',
                'BankTransaction', (string)$bankTxId,
                json_encode(['status' => $bank['status']]),
                json_encode(['tuition_id' => $tuitionId, 'payment_id' => $result['payment_id'],
                    'amount' => (int)$bank['transfer_amount'], 'note' => $note], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
            return ['ok' => true, 'payment_id' => $result['payment_id'],
                'message' => 'Đã gán giao dịch vào hóa đơn #' . $tuitionId . '.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public static function rejectUnmatched(int $bankTxId, int $adminId, string $note): array {
        $pdo = get_db();
        try {
            $q = $pdo->prepare('SELECT status FROM BankTransaction WHERE bank_transaction_id=:id');
            $q->execute([':id' => $bankTxId]);
            $status = $q->fetchColumn();
            if ($status === false) return ['ok' => false, 'message' => 'Không tìm thấy giao dịch.'];
            if (!in_array($status, ['Unmatched', 'Rejected'], true)) {
                return ['ok' => false, 'message' => 'Giao dịch đã được xử lý trước đó.'];
            }
            $pdo->prepare(
                "UPDATE BankTransaction SET status='Rejected', reviewed_by=:admin,
                        reviewed_at=NOW(), processed_at=NOW(), match_note=:note
                 WHERE bank_transaction_id=:id"
            )->execute([':admin' => $adminId, ':note' => self::short($note, 500), ':id' => $bankTxId]);
            AcademicAlertService::writeAuditLog($pdo, $adminId, 'PAYMENT_REVIEW_REJECTED',
                'BankTransaction', (string)$bankTxId, json_encode(['status' => $status]),
                json_encode(['note' => $note], JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'message' => 'Đã từ chối giao dịch.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Không thể cập nhật giao dịch.'];
        }
    }

    public static function resolveOverpayment(int $paymentId, int $adminId, string $note): array {
        $pdo = get_db();
        $q = $pdo->prepare('SELECT * FROM Payment WHERE payment_id=:id');
        $q->execute([':id' => $paymentId]);
        $payment = $q->fetch();
        if (!$payment || (int)$payment['overpaid_vnd'] <= 0) {
            return ['ok' => false, 'message' => 'Không tìm thấy khoản thu vượt cần xử lý.'];
        }
        $pdo->prepare("UPDATE Payment SET review_status='Resolved' WHERE payment_id=:id")
            ->execute([':id' => $paymentId]);
        AcademicAlertService::writeAuditLog($pdo, $adminId, 'OVERPAYMENT_RESOLVED',
            'Payment', (string)$paymentId, json_encode(['review_status' => $payment['review_status']]),
            json_encode(['overpaid_vnd' => (int)$payment['overpaid_vnd'], 'note' => $note], JSON_UNESCAPED_UNICODE));
        return ['ok' => true, 'message' => 'Đã đánh dấu xử lý khoản thu vượt.'];
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private static function accountMatches(string $configured, string $received): bool {
        $c = preg_replace('/[^0-9A-Za-z]/', '', $configured);
        $r = preg_replace('/[^0-9A-Za-z]/', '', $received);
        if ($c === '' || $r === '') return false;
        return hash_equals(strtolower($c), strtolower($r));
    }

    private static function parseDate(string $value): ?string {
        if (trim($value) === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private static function short(string $value, int $max): string {
        return mb_substr(trim($value), 0, $max);
    }

    private static function studentAccountId(PDO $pdo, string $studentId): int {
        $q = $pdo->prepare("SELECT account_id FROM Account WHERE student_id=:sid AND role='Student' LIMIT 1");
        $q->execute([':sid' => $studentId]);
        return (int)$q->fetchColumn();
    }

    private static function notifyAdmins(string $title, string $body, bool $isTest): void {
        try {
            NotificationService::broadcast('AcademicAffairs', 'Payment',
                ($isTest ? '[TEST] ' : '') . $title, $body,
                app_url('assets/admin/tuition.php?tab=unmatched'));
        } catch (Throwable) {}
    }
}
