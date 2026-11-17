<?php
/**
 * UniPortal – Seed v14: expanded course catalog, curriculum mappings,
 * lecturer profiles/accounts, current-semester sections and teaching schedules.
 *
 * Idempotent: re-running only fills in what is missing; it never deletes
 * existing records and never overwrites existing accounts or academic data.
 *
 * Run:
 *   C:/xampp/php/php.exe database/seed_v14_catalog_lecturers.php
 */

require_once __DIR__ . '/../src/src/config/database.php';
require_once __DIR__ . '/../src/src/models/TeachingSchedule.php';

$pdo = get_db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$ADMIN_ACCOUNT_ID = (int)$pdo->query("SELECT account_id FROM Account WHERE role='AcademicAffairs' ORDER BY account_id LIMIT 1")->fetchColumn();
$SEMESTER_ID = 7; // HK1 2026-2027, Active semester
$stats = [
    'courses_inserted' => 0, 'course_major_rows' => 0, 'curriculum_rows' => 0,
    'lecturers_inserted' => 0, 'accounts_inserted' => 0,
    'sections_inserted' => 0, 'schedules_saved' => 0, 'sessions_added' => 0,
    'periods_mapped' => 0, 'errors' => [],
];

// ---------------------------------------------------------------------------
// Reference data
// ---------------------------------------------------------------------------
$deptIds = [];
foreach ($pdo->query('SELECT department_id, department_code FROM Department')->fetchAll() as $d) {
    $deptIds[$d['department_code']] = (int)$d['department_id'];
}
$majorIds = [];
foreach ($pdo->query('SELECT major_id, major_code FROM Major')->fetchAll() as $m) {
    $majorIds[$m['major_code']] = (int)$m['major_id'];
}

$ALL_MAJORS = array_keys($majorIds);

// Course catalog: [code, name, credits, department_code, category, eligible majors ('*' = all), prerequisite code, description]
$COURSES = [
    // ── General education (all majors) ──────────────────────────────────────
    ['GE1101','Nhập môn Triết học Mác – Lênin',3,'GE','Giáo dục đại cương',['*'],null,'Các nguyên lý cơ bản của triết học Mác – Lênin và vận dụng vào nhận thức thực tiễn.'],
    ['GE1102','Kinh tế chính trị Mác – Lênin',3,'GE','Giáo dục đại cương',['*'],null,'Học thuyết giá trị, giá trị thặng dư và các quy luật kinh tế cơ bản của chủ nghĩa tư bản.'],
    ['GE1103','Chủ nghĩa xã hội khoa học',2,'GE','Giáo dục đại cương',['*'],null,'Sứ mệnh lịch sử của giai cấp công nhân và con đường xây dựng chủ nghĩa xã hội.'],
    ['GE1104','Lịch sử Đảng Cộng sản Việt Nam',2,'GE','Giáo dục đại cương',['*'],null,'Các giai đoạn lãnh đạo cách mạng của Đảng và bài học kinh nghiệm.'],
    ['GE1105','Tư tưởng Hồ Chí Minh',2,'GE','Giáo dục đại cương',['*'],null,'Hệ thống tư tưởng Hồ Chí Minh về độc lập dân tộc và phát triển đất nước.'],
    ['GE1106','Pháp luật đại cương',2,'GE','Giáo dục đại cương',['*'],null,'Kiến thức nền tảng về nhà nước, pháp luật và quyền công dân.'],
    ['GE1107','Tin học cơ bản',2,'GE','Giáo dục đại cương',['*'],null,'Kỹ năng sử dụng máy tính, văn phòng điện tử và khai thác Internet an toàn.'],
    ['GE1108','Giáo dục thể chất 1',2,'GE','Giáo dục đại cương',['*'],null,'Rèn luyện thể lực, kỹ thuật vận động cơ bản và lối sống lành mạnh.'],
    ['GE1109','Kỹ năng mềm và giao tiếp',2,'GE','Giáo dục đại cương',['*'],null,'Kỹ năng làm việc nhóm, quản lý thời gian, thuyết trình và giao tiếp hiệu quả.'],
    ['GE1110','Đổi mới sáng tạo và khởi nghiệp',3,'GE','Giáo dục đại cương',['*'],null,'Quy trình hình thành ý tưởng, xây dựng mô hình kinh doanh và khởi nghiệp tinh gọn.'],

    // ── Economics ───────────────────────────────────────────────────────────
    ['ECO2201','Kinh tế vi mô',3,'ECO','Kinh tế học',['ECO','MBA','MKT','IB','AC','BEL'],null,'Lựa chọn tiêu dùng, sản xuất và cân bằng thị trường của doanh nghiệp và hộ gia đình.'],
    ['ECO2202','Kinh tế vĩ mô',3,'ECO','Kinh tế học',['ECO','MBA','MKT','IB','AC','BEL'],'ECO2201','Tổng cung – tổng cầu, lạm phát, thất nghiệp và chính sách vĩ mô.'],
    ['ECO2203','Kinh tế lượng',3,'ECO','Kinh tế học',['ECO','BDA','MBA','AC'],'ECO2201','Hồi quy tuyến tính và ứng dụng mô hình định lượng trong phân tích kinh tế.'],
    ['ECO2204','Kinh tế phát triển',3,'ECO','Kinh tế học',['ECO','MBA','IB'],null,'Tăng trưởng, phát triển bền vững và chính sách phát triển ở các nước đang phát triển.'],
    ['ECO2205','Kinh tế quốc tế',3,'ECO','Kinh tế học',['ECO','IB','MBA'],'ECO2202','Lý thuyết thương mại quốc tế, tỷ giá và hội nhập kinh tế.'],
    ['ECO2206','Kinh tế số',2,'ECO','Kinh tế học',['ECO','ICE','BDA','MBA'],null,'Tác động của công nghệ số đến sản xuất, thị trường lao động và mô hình kinh doanh.'],

    // ── Business administration / Management ────────────────────────────────
    ['BUS2301','Quản trị học',3,'MBA','Quản trị kinh doanh',['MBA','MKT','IB','ECO','AC','BEL'],null,'Chức năng hoạch định, tổ chức, lãnh đạo và kiểm soát trong doanh nghiệp.'],
    ['BUS2302','Quản trị chiến lược',3,'MBA','Quản trị kinh doanh',['MBA','MKT','IB','ECO'],'BUS2301','Phân tích môi trường, xây dựng và triển khai chiến lược cạnh tranh.'],
    ['BUS2303','Quản trị nguồn nhân lực',3,'MBA','Quản trị kinh doanh',['MBA','MKT','IB','ECO','BEL'],null,'Tuyển dụng, đào tạo, đánh giá và phát triển nguồn nhân lực.'],
    ['BUS2304','Hành vi tổ chức',2,'MBA','Quản trị kinh doanh',['MBA','MKT','IB','ECO','BEL'],null,'Hành vi cá nhân, nhóm và văn hóa tổ chức trong môi trường làm việc.'],
    ['BUS2305','Quản trị vận hành',3,'MBA','Quản trị kinh doanh',['MBA','IB','ECO'],'BUS2301','Quản lý quy trình sản xuất, tồn kho, chất lượng và năng suất.'],
    ['BUS2306','Đạo đức kinh doanh',2,'MBA','Quản trị kinh doanh',['MBA','MKT','IB','ECO','AC','BEL'],null,'Chuẩn mực đạo đức, trách nhiệm xã hội và quản trị bền vững.' ],

    // ── Marketing ───────────────────────────────────────────────────────────
    ['MKT2401','Nguyên lý Marketing',3,'MKT','Marketing',['MKT','MBA','IB','BEL','ECO'],null,'Phân khúc, định vị, marketing mix và quy trình xây dựng kế hoạch marketing.'],
    ['MKT2402','Hành vi khách hàng',3,'MKT','Marketing',['MKT','MBA','IB'],'MKT2401','Các yếu tố ảnh hưởng đến quyết định mua và hành trình khách hàng.'],
    ['MKT2403','Marketing số',3,'MKT','Marketing',['MKT','MBA','IB','BEL'],'MKT2401','SEO, quảng cáo trả phí, mạng xã hội và đo lường hiệu quả kênh số.'],
    ['MKT2404','Quản trị thương hiệu',2,'MKT','Marketing',['MKT','MBA','IB'],'MKT2401','Xây dựng, định vị và quản lý tài sản thương hiệu.'],
    ['MKT2405','Nghiên cứu thị trường',3,'MKT','Marketing',['MKT','BDA','MBA','IB'],'MKT2401','Thiết kế nghiên cứu, thu thập dữ liệu và phân tích insight thị trường.'],
    ['MKT2406','Marketing quốc tế',3,'MKT','Marketing',['MKT','IB','MBA'],null,'Chiến lược marketing xuyên quốc gia và thích ứng văn hóa địa phương.'],
    ['MKT2407','Truyền thông marketing',2,'MKT','Marketing',['MKT','BEL','MBA'],null,'Lập kế hoạch truyền thông tích hợp và sáng tạo nội dung.'],
    ['MKT2408','Thương mại điện tử',3,'MKT','Marketing',['MKT','IB','BEL','MBA'],null,'Mô hình kinh doanh số, sàn thương mại điện tử và trải nghiệm khách hàng.'],

    // ── Accounting & Finance ────────────────────────────────────────────────
    ['ACC2501','Nguyên lý kế toán',3,'AC','Kế toán – Tài chính',['AC','ECO','MBA','IB'],null,'Hệ thống tài khoản, ghi nhận và trình bày báo cáo tài chính cơ bản.'],
    ['ACC2502','Kế toán tài chính',3,'AC','Kế toán – Tài chính',['AC','MBA'],'ACC2501','Kế toán các khoản mục và lập báo cáo tài chính theo chuẩn mực.'],
    ['ACC2503','Kế toán quản trị',3,'AC','Kế toán – Tài chính',['AC','MBA','ECO'],'ACC2501','Chi phí, dự toán và thông tin kế toán phục vụ ra quyết định quản trị.'],
    ['ACC2504','Kiểm toán căn bản',3,'AC','Kế toán – Tài chính',['AC'],'ACC2502','Quy trình kiểm toán, rủi ro và bằng chứng kiểm toán.'],
    ['ACC2505','Thuế và kế toán thuế',2,'AC','Kế toán – Tài chính',['AC','ECO','MBA'],null,'Các loại thuế chính và phương pháp kê khai, hạch toán thuế.'],
    ['FIN2506','Tài chính doanh nghiệp',3,'AC','Kế toán – Tài chính',['AC','ECO','MBA','IB'],null,'Quyết định đầu tư, tài trợ và quản lý vốn của doanh nghiệp.'],
    ['FIN2507','Thị trường chứng khoán',3,'AC','Kế toán – Tài chính',['AC','ECO','MBA'],'FIN2506','Cấu trúc thị trường, định giá cổ phiếu và chiến lược đầu tư chứng khoán.'],
    ['FIN2508','Phân tích đầu tư',3,'AC','Kế toán – Tài chính',['AC','ECO','MBA'],'FIN2506','Phân tích cơ bản, phân tích kỹ thuật và quản lý danh mục đầu tư.'],
    ['FIN2509','Ngân hàng thương mại',2,'AC','Kế toán – Tài chính',['AC','ECO','MBA'],null,'Hoạt động tín dụng, huy động vốn và quản trị ngân hàng thương mại.'],
    ['FIN2510','Quản trị rủi ro tài chính',3,'AC','Kế toán – Tài chính',['AC','ECO','MBA'],'FIN2506','Nhận diện, đo lường và phòng ngừa rủi ro tài chính doanh nghiệp.'],

    // ── International business ─────────────────────────────────────────────
    ['IB2601','Kinh doanh quốc tế căn bản',3,'IB','Kinh doanh quốc tế',['IB','BEL','MBA','MKT','ECO'],null,'Môi trường kinh doanh quốc tế, lợi thế cạnh tranh và chiến lược toàn cầu.'],
    ['IB2602','Quản trị xuất nhập khẩu',3,'IB','Kinh doanh quốc tế',['IB','BEL','MBA'],'IB2601','Quy trình hợp đồng, chứng từ, vận tải và thủ tục hải quan.'],
    ['IB2603','Logistics và chuỗi cung ứng',3,'IB','Kinh doanh quốc tế',['IB','BEL','MBA','ECO'],null,'Thiết kế mạng lưới cung ứng, quản trị tồn kho và dịch vụ khách hàng.'],
    ['IB2604','Đàm phán thương mại quốc tế',2,'IB','Kinh doanh quốc tế',['IB','BEL','MBA'],null,'Kỹ thuật đàm phán đa văn hóa và giải quyết xung đột trong thương mại.'],
    ['IB2605','Luật kinh doanh quốc tế',3,'IB','Kinh doanh quốc tế',['IB','BEL','MBA'],null,'Các nguyên tắc pháp lý của hợp đồng, đầu tư và giải quyết tranh chấp quốc tế.'],
    ['IB2606','Thanh toán quốc tế',2,'IB','Kinh doanh quốc tế',['IB','AC','BEL'],'IB2601','Phương thức thanh toán, tín dụng chứng từ và quản lý rủi ro tỷ giá.'],
    ['IB2607','Kinh doanh đa quốc gia',3,'IB','Kinh doanh quốc tế',['IB','MBA','MKT'],null,'Chiến lược của công ty đa quốc gia và quản trị hoạt động toàn cầu.'],

    // ── Information systems ────────────────────────────────────────────────
    ['MIS2701','Hệ thống thông tin quản lý',3,'MIS','Hệ thống thông tin',['MIS','BDA','AAI','ICE','AIT'],null,'Vai trò của hệ thống thông tin trong tổ chức và quy trình ra quyết định.'],
    ['MIS2702','Phân tích thiết kế hệ thống',3,'MIS','Hệ thống thông tin',['MIS','BDA','AIT'],'MIS2701','Thu thập yêu cầu, mô hình hóa nghiệp vụ và thiết kế hệ thống.'],
    ['MIS2703','Cơ sở dữ liệu',3,'MIS','Hệ thống thông tin',['MIS','BDA','AIT','ICE','AAI'],null,'Mô hình quan hệ, SQL, chuẩn hóa và thiết kế cơ sở dữ liệu.'],
    ['MIS2704','Quản trị dự án CNTT',3,'MIS','Hệ thống thông tin',['MIS','AIT','ICE'],'MIS2701','Lập kế hoạch, quản lý tiến độ, chi phí và rủi ro dự án công nghệ.'],
    ['MIS2705','Thương mại điện tử và chuyển đổi số',3,'MIS','Hệ thống thông tin',['MIS','BDA','MBA','MKT'],null,'Mô hình kinh doanh số và lộ trình chuyển đổi số doanh nghiệp.'],
    ['MIS2706','An toàn thông tin doanh nghiệp',3,'MIS','Hệ thống thông tin',['MIS','AIT','ICE'],null,'Chính sách bảo mật, quản lý rủi ro và ứng phó sự cố an ninh mạng.'],
    ['MIS2707','ERP và quản trị nguồn lực',3,'MIS','Hệ thống thông tin',['MIS','BDA','MBA'],'MIS2701','Kiến trúc ERP, tích hợp quy trình và triển khai hệ thống doanh nghiệp.'],
    ['MIS2708','Kho dữ liệu và BI',3,'MIS','Hệ thống thông tin',['MIS','BDA','AIT'],'MIS2703','Thiết kế kho dữ liệu, ETL và báo cáo phân tích kinh doanh.'],

    // ── Information technology ─────────────────────────────────────────────
    ['AIT2801','Lập trình căn bản',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS','BDA','AAI'],null,'Tư duy lập trình, cấu trúc điều khiển và hàm với ngôn ngữ hiện đại.'],
    ['AIT2802','Lập trình hướng đối tượng',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS'],'AIT2801','Lớp, đối tượng, kế thừa, đa hình và thiết kế phần mềm hướng đối tượng.'],
    ['AIT2803','Cấu trúc dữ liệu và giải thuật',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS'],'AIT2801','Phân tích độ phức tạp, danh sách, cây, đồ thị và thuật toán sắp xếp/tìm kiếm.'],
    ['AIT2804','Mạng máy tính',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS','AAI'],null,'Mô hình TCP/IP, định tuyến, chuyển mạch và an ninh mạng cơ bản.'],
    ['AIT2805','Phát triển ứng dụng web',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS'],'AIT2802','Xây dựng ứng dụng web full-stack, API và triển khai dịch vụ.'],
    ['AIT2806','Điện toán đám mây',3,'AIT','Công nghệ thông tin',['AIT','ICE','MIS'],null,'Dịch vụ đám mây, ảo hóa, container và vận hành hệ thống phân tán.'],
    ['AIT2807','Trí tuệ nhân tạo ứng dụng',3,'AIT','Công nghệ thông tin',['AIT','ICE','BDA','AAI','MIS'],'AIT2801','Các mô hình AI, học máy và ứng dụng vào bài toán doanh nghiệp.'],
    ['AIT2808','Đồ án công nghệ thông tin',2,'AIT','Công nghệ thông tin',['AIT','ICE'],'AIT2805','Thực hiện dự án phần mềm hoàn chỉnh theo quy trình kỹ thuật.'],

    // ── Data analytics ─────────────────────────────────────────────────────
    ['BDA2901','Nhập môn phân tích dữ liệu',3,'BDA','Phân tích dữ liệu',['BDA','MIS','AIT','ICE','AAI'],null,'Quy trình phân tích dữ liệu, làm sạch dữ liệu và kể chuyện bằng dữ liệu.'],
    ['BDA2902','Thống kê ứng dụng',3,'BDA','Phân tích dữ liệu',['BDA','MIS','ECO','AC'],'BDA2901','Thống kê mô tả, suy luận và kiểm định giả thuyết trong kinh doanh.'],
    ['BDA2903','Trực quan hóa dữ liệu',2,'BDA','Phân tích dữ liệu',['BDA','MIS','AIT'],'BDA2901','Thiết kế dashboard và biểu đồ hiệu quả cho báo cáo quản trị.'],
    ['BDA2904','Học máy cơ bản',3,'BDA','Phân tích dữ liệu',['BDA','AIT','ICE','AAI','MIS'],'BDA2902','Học có giám sát, không giám sát và đánh giá mô hình dự báo.'],
    ['BDA2905','Phân tích dữ liệu lớn',3,'BDA','Phân tích dữ liệu',['BDA','AIT','ICE','MIS'],'BDA2902','Xử lý dữ liệu lớn, lưu trữ phân tán và tính toán song song.'],
    ['BDA2906','Đồ án phân tích dữ liệu',2,'BDA','Phân tích dữ liệu',['BDA'],'BDA2904','Thực hiện dự án phân tích dữ liệu end-to-end cho một bài toán thực tế.'],

    // ── Languages & communication ──────────────────────────────────────────
    ['ENG3001','Tiếng Anh giao tiếp 1',2,'BEL','Ngôn ngữ & Truyền thông',['*'],null,'Phát triển kỹ năng nghe – nói tiếng Anh trong các tình huống hằng ngày.'],
    ['ENG3002','Tiếng Anh giao tiếp 2',2,'BEL','Ngôn ngữ & Truyền thông',['*'],'ENG3001','Giao tiếp tiếng Anh nâng cao trong môi trường học thuật và nghề nghiệp.'],
    ['ENG3003','Tiếng Anh học thuật',2,'BEL','Ngôn ngữ & Truyền thông',['*'],'ENG3002','Đọc hiểu, viết luận và thuyết trình học thuật bằng tiếng Anh.'],
    ['ENG3004','Tiếng Anh thương mại',3,'BEL','Ngôn ngữ & Truyền thông',['BEL','IB','MBA','MKT','ECO','AC'],null,'Tiếng Anh trong thư tín, hợp đồng và giao tiếp kinh doanh.'],
    ['COM3005','Kỹ năng thuyết trình',2,'BEL','Ngôn ngữ & Truyền thông',['*'],null,'Thiết kế bài trình bày, kiểm soát giọng nói và xử lý câu hỏi.'],
    ['COM3006','Viết báo cáo và tài liệu kỹ thuật',2,'BEL','Ngôn ngữ & Truyền thông',['MIS','AIT','ICE','BDA','AAI'],null,'Viết tài liệu yêu cầu, báo cáo kỹ thuật và hướng dẫn sử dụng.'],
    ['COM3007','Truyền thông đa phương tiện',3,'BEL','Ngôn ngữ & Truyền thông',['BEL','MKT','ICE'],null,'Sản xuất nội dung số, hình ảnh, video và kể chuyện đa nền tảng.'],
    ['COM3008','Giao tiếp liên văn hóa',2,'BEL','Ngôn ngữ & Truyền thông',['BEL','IB','MBA','MKT'],null,'Nhận thức văn hóa và kỹ năng giao tiếp trong môi trường đa quốc gia.'],

    // ── Management ─────────────────────────────────────────────────────────
    ['MGT3101','Quản trị chất lượng',3,'MBA','Quản lý',['MBA','MKT','IB','ECO','AC'],null,'Hệ thống quản lý chất lượng, ISO và cải tiến liên tục.'],
    ['MGT3102','Quản trị thay đổi',2,'MBA','Quản lý',['MBA','MKT','IB'],null,'Mô hình quản trị thay đổi và dẫn dắt chuyển đổi tổ chức.'],
    ['MGT3103','Khởi nghiệp và đổi mới',3,'MBA','Quản lý',['MBA','MKT','IB','ECO','BEL'],'BUS2301','Xây dựng mô hình kinh doanh, gọi vốn và phát triển sản phẩm mới.'],
    ['MGT3104','Quản trị tri thức',2,'MBA','Quản lý',['MBA','MKT','IB'],null,'Tạo lập, chia sẻ và khai thác tri thức trong tổ chức.'],
    ['MGT3105','Phân tích quyết định',3,'MBA','Quản lý',['MBA','ECO','BDA'],null,'Mô hình ra quyết định, cây quyết định và phân tích độ nhạy.'],
    ['MGT3106','Đồ án quản trị',2,'MBA','Quản lý',['MBA'],'MGT3101','Nghiên cứu và đề xuất giải pháp quản trị cho doanh nghiệp.'],

    // ── Automation & AI ────────────────────────────────────────────────────
    ['AAI3201','Nhập môn tự động hóa',3,'AAI','Tự động hóa & AI',['AAI','AIT','ICE'],null,'Nguyên lý hệ thống tự động, cảm biến và cơ cấu chấp hành.'],
    ['AAI3202','Hệ thống điều khiển thông minh',3,'AAI','Tự động hóa & AI',['AAI','AIT','ICE'],'AAI3201','Điều khiển PID, logic mờ và ứng dụng điều khiển thông minh.'],
    ['AAI3203','Robot và ứng dụng',3,'AAI','Tự động hóa & AI',['AAI','AIT','ICE'],null,'Động học robot, lập trình và ứng dụng robot trong sản xuất.'],
    ['AAI3204','IoT và hệ thống nhúng',3,'AAI','Tự động hóa & AI',['AAI','AIT','ICE'],'AAI3201','Kiến trúc IoT, giao thức truyền thông và lập trình thiết bị nhúng.'],
    ['AAI3205','Đồ án tự động hóa',2,'AAI','Tự động hóa & AI',['AAI'],'AAI3202','Thiết kế và triển khai mô hình tự động hóa quy mô nhỏ.'],

    // ── Digital economy ────────────────────────────────────────────────────
    ['ICE3301','Kinh tế số căn bản',3,'ICE','Kinh tế số',['ICE','ECO','MBA','MIS'],null,'Nền kinh tế số, dữ liệu và các mô hình kinh doanh nền tảng.'],
    ['ICE3302','Phân tích nghiệp vụ số',3,'ICE','Kinh tế số',['ICE','MIS','BDA'],'ICE3301','Mô hình hóa quy trình nghiệp vụ và phân tích yêu cầu chuyển đổi số.'],
    ['ICE3303','Đổi mới số trong doanh nghiệp',3,'ICE','Kinh tế số',['ICE','MBA','MKT'],null,'Chiến lược đổi mới số, văn hóa số và đo lường hiệu quả chuyển đổi.'],
    ['ICE3304','Nền tảng dữ liệu số',3,'ICE','Kinh tế số',['ICE','MIS','AIT'],'ICE3301','Kiến trúc nền tảng dữ liệu, API và hệ sinh thái dịch vụ số.'],
    ['ICE3305','Đồ án kinh tế số',2,'ICE','Kinh tế số',['ICE'],'ICE3303','Thực hiện dự án chuyển đổi số cho một doanh nghiệp mẫu.'],
];

// Existing course categories (only fill NULL values)
$EXISTING_CATEGORY = [
    'MIS2101' => 'Hệ thống thông tin', 'BEL2101' => 'Ngôn ngữ & Truyền thông',
    'AIT2101' => 'Công nghệ thông tin', 'IB2101'  => 'Kinh doanh quốc tế',
    'BDA2101' => 'Phân tích dữ liệu', 'AAI2101' => 'Tự động hóa & AI',
    'ICE2101' => 'Kinh tế số', 'AC2101'  => 'Kế toán – Tài chính',
    'INS3056' => 'Hệ thống thông tin',
];
// Existing course eligibility
$EXISTING_ELIGIBLE = [
    'MIS2101' => ['MIS','BDA','AAI','ICE','AIT'],
    'BEL2101' => ['*'],
    'AIT2101' => ['AIT','ICE','MIS','BDA','AAI'],
    'IB2101'  => ['IB','BEL','MBA','MKT','ECO'],
    'BDA2101' => ['BDA','MIS','AIT','ICE','AAI'],
    'AAI2101' => ['AAI','AIT','ICE'],
    'ICE2101' => ['ICE','ECO','MBA','MIS'],
    'AC2101'  => ['AC','ECO','MBA','IB'],
    'INS3056' => ['MIS','BDA','AIT','ICE','AAI','MBA'],
];

// Lecturer seed profiles: [full name, dept code, academic title, position, specialization]
$LECTURERS = [
    ['Nguyễn Văn Hùng','MIS','PGS.TS.','Trưởng bộ môn','Hệ thống thông tin doanh nghiệp'],
    ['Trần Thị Mai','MIS','TS.','Phó trưởng bộ môn','Quản trị dữ liệu và ERP'],
    ['Lê Quang Minh','MIS','TS.','Giảng viên chính','Phân tích thiết kế hệ thống'],
    ['Phạm Thu Hà','MIS','ThS.','Giảng viên','Thương mại điện tử'],
    ['Hoàng Đức Anh','MIS','ThS.','Giảng viên','An toàn thông tin'],
    ['Vũ Thị Lan','MIS','TS.','Giảng viên','Kho dữ liệu và BI'],
    ['Đặng Minh Tuấn','MIS','ThS.','Giảng viên','Quản trị dự án CNTT'],

    ['Nguyễn Thị Hương','BEL','TS.','Trưởng bộ môn','Ngôn ngữ Anh thương mại'],
    ['Trần Quốc Bảo','BEL','ThS.','Giảng viên','Tiếng Anh giao tiếp'],
    ['Lê Thanh Hoa','BEL','ThS.','Giảng viên','Tiếng Anh học thuật'],
    ['Phạm Minh Châu','BEL','TS.','Giảng viên chính','Truyền thông đa phương tiện'],
    ['Đỗ Hoàng Nam','BEL','ThS.','Giảng viên','Giao tiếp liên văn hóa'],

    ['Bùi Văn Khoa','AIT','PGS.TS.','Trưởng bộ môn','Kỹ thuật phần mềm'],
    ['Nguyễn Thị Ngọc','AIT','TS.','Phó trưởng bộ môn','Trí tuệ nhân tạo'],
    ['Trần Đức Thịnh','AIT','TS.','Giảng viên chính','Cấu trúc dữ liệu và giải thuật'],
    ['Lê Hoàng Phúc','AIT','ThS.','Giảng viên','Phát triển ứng dụng web'],
    ['Phạm Quốc Việt','AIT','ThS.','Giảng viên','Mạng máy tính'],
    ['Hoàng Anh Tuấn','AIT','TS.','Giảng viên','Điện toán đám mây'],
    ['Vũ Thị Trang','AIT','ThS.','Giảng viên','Lập trình hướng đối tượng'],

    ['Nguyễn Xuân Trường','IB','PGS.TS.','Trưởng bộ môn','Kinh doanh quốc tế'],
    ['Trần Thị Bích','IB','TS.','Giảng viên chính','Logistics và chuỗi cung ứng'],
    ['Lê Văn Dũng','IB','ThS.','Giảng viên','Xuất nhập khẩu'],
    ['Phạm Thu Thủy','IB','TS.','Giảng viên','Luật kinh doanh quốc tế'],
    ['Hoàng Quốc Cường','IB','ThS.','Giảng viên','Thanh toán quốc tế'],

    ['Nguyễn Thị Thu','BDA','TS.','Trưởng bộ môn','Phân tích dữ liệu kinh doanh'],
    ['Trần Văn Lâm','BDA','TS.','Phó trưởng bộ môn','Học máy ứng dụng'],
    ['Lê Minh Quân','BDA','ThS.','Giảng viên','Trực quan hóa dữ liệu'],
    ['Phạm Thị Yến','BDA','TS.','Giảng viên','Thống kê ứng dụng'],
    ['Hoàng Văn Sơn','BDA','ThS.','Giảng viên','Dữ liệu lớn'],
    ['Vũ Thị Hạnh','BDA','ThS.','Giảng viên','Phân tích nghiệp vụ'],

    ['Nguyễn Đức Long','AAI','PGS.TS.','Trưởng bộ môn','Tự động hóa và điều khiển'],
    ['Trần Thị Hồng','AAI','TS.','Giảng viên chính','Robot và hệ thống thông minh'],
    ['Lê Văn Tú','AAI','ThS.','Giảng viên','IoT và hệ thống nhúng'],
    ['Phạm Quang Huy','AAI','TS.','Giảng viên','Điều khiển thông minh'],
    ['Hoàng Thị Linh','AAI','ThS.','Giảng viên','Cảm biến và cơ cấu chấp hành'],

    ['Nguyễn Văn Thành','ICE','TS.','Trưởng bộ môn','Kinh tế số'],
    ['Trần Minh Hiếu','ICE','TS.','Phó trưởng bộ môn','Chuyển đổi số doanh nghiệp'],
    ['Lê Thị Phương','ICE','ThS.','Giảng viên','Phân tích nghiệp vụ số'],
    ['Phạm Đức Duy','ICE','ThS.','Giảng viên','Nền tảng dữ liệu số'],
    ['Hoàng Văn Nghĩa','ICE','TS.','Giảng viên','Đổi mới số'],
    ['Vũ Minh Khôi','ICE','ThS.','Giảng viên','Kinh doanh số'],

    ['Nguyễn Thị Kim','AC','PGS.TS.','Trưởng bộ môn','Kế toán tài chính'],
    ['Trần Văn Hải','AC','TS.','Phó trưởng bộ môn','Kiểm toán'],
    ['Lê Thu Giang','AC','TS.','Giảng viên chính','Kế toán quản trị'],
    ['Phạm Minh Đức','AC','ThS.','Giảng viên','Thuế và kế toán thuế'],
    ['Hoàng Thị Thanh','AC','TS.','Giảng viên','Tài chính doanh nghiệp'],
    ['Vũ Quốc Hưng','AC','ThS.','Giảng viên','Thị trường chứng khoán'],

    ['Nguyễn Thị Bình','GE','TS.','Trưởng bộ môn','Triết học và khoa học xã hội'],
    ['Trần Văn An','GE','TS.','Giảng viên chính','Kinh tế chính trị'],
    ['Lê Thị Cẩm','GE','ThS.','Giảng viên','Pháp luật đại cương'],
    ['Phạm Đức Hòa','GE','ThS.','Giảng viên','Tin học cơ bản'],
    ['Hoàng Thị Diệp','GE','ThS.','Giảng viên','Kỹ năng mềm'],

    ['Nguyễn Văn Phúc','ECO','PGS.TS.','Trưởng bộ môn','Kinh tế học'],
    ['Trần Thị Lệ','ECO','TS.','Phó trưởng bộ môn','Kinh tế lượng'],
    ['Lê Quang Vinh','ECO','TS.','Giảng viên chính','Kinh tế vĩ mô'],
    ['Phạm Thu Hằng','ECO','ThS.','Giảng viên','Kinh tế phát triển'],
    ['Hoàng Minh Triết','ECO','ThS.','Giảng viên','Kinh tế quốc tế'],

    ['Nguyễn Thị Thảo','MKT','TS.','Trưởng bộ môn','Marketing số'],
    ['Trần Hoàng Sơn','MKT','TS.','Phó trưởng bộ môn','Nghiên cứu thị trường'],
    ['Lê Thị Ngân','MKT','ThS.','Giảng viên','Quản trị thương hiệu'],
    ['Phạm Văn Khánh','MKT','ThS.','Giảng viên','Truyền thông marketing'],
    ['Hoàng Thu Trang','MKT','TS.','Giảng viên','Marketing quốc tế'],
    ['Vũ Đức Thắng','MKT','ThS.','Giảng viên','Thương mại điện tử'],

    ['Nguyễn Văn Toàn','MBA','PGS.TS.','Trưởng bộ môn','Quản trị chiến lược'],
    ['Trần Thị Minh','MBA','TS.','Phó trưởng bộ môn','Quản trị nguồn nhân lực'],
    ['Lê Hoàng Long','MBA','TS.','Giảng viên chính','Quản trị vận hành'],
    ['Phạm Thu Hà','MBA','ThS.','Giảng viên','Quản trị chất lượng'],
    ['Hoàng Quốc Bảo','MBA','ThS.','Giảng viên','Khởi nghiệp và đổi mới'],
    ['Vũ Thị Mai','MBA','TS.','Giảng viên','Hành vi tổ chức'],
    ['Đặng Văn Lực','MBA','ThS.','Giảng viên','Quản trị thay đổi'],
];

// ---------------------------------------------------------------------------
// 1. Courses
// ---------------------------------------------------------------------------
$courseIdByCode = [];
$findCourse = $pdo->prepare('SELECT course_id FROM Course WHERE course_code=:c LIMIT 1');
$insertCourse = $pdo->prepare(
    'INSERT INTO Course (course_code, course_name, credits, department_id, description, is_active, category, default_sessions)
     VALUES (:code, :name, :credits, :dept, :descr, 1, :cat, :sessions)'
);
$updateCourseMeta = $pdo->prepare(
    'UPDATE Course SET category = COALESCE(category, :cat), default_sessions = COALESCE(default_sessions, :sessions)
     WHERE course_id = :id'
);
foreach ($COURSES as $row) {
    [$code, $name, $credits, $deptCode, $category, , , $descr] = $row;
    $sessions = $credits * TeachingSchedule::SESSIONS_PER_CREDIT;
    $findCourse->execute([':c' => $code]);
    $existingId = (int)($findCourse->fetchColumn() ?: 0);
    if ($existingId > 0) {
        $updateCourseMeta->execute([':cat' => $category, ':sessions' => $sessions, ':id' => $existingId]);
        $courseIdByCode[$code] = $existingId;
    } else {
        $insertCourse->execute([
            ':code' => $code, ':name' => $name, ':credits' => $credits,
            ':dept' => $deptIds[$deptCode] ?? null, ':descr' => $descr,
            ':cat' => $category, ':sessions' => $sessions,
        ]);
        $courseIdByCode[$code] = (int)$pdo->lastInsertId();
        $stats['courses_inserted']++;
    }
}

// Fill categories for the pre-existing catalog courses without overwriting.
$updExisting = $pdo->prepare('UPDATE Course SET category = :cat WHERE course_code = :code AND category IS NULL');
foreach ($EXISTING_CATEGORY as $code => $cat) {
    $updExisting->execute([':cat' => $cat, ':code' => $code]);
}
foreach ($EXISTING_ELIGIBLE as $code => $majors) {
    $findCourse->execute([':c' => $code]);
    $cid = (int)($findCourse->fetchColumn() ?: 0);
    if ($cid > 0) $courseIdByCode[$code] = $cid;
}

// ---------------------------------------------------------------------------
// 2. Course ↔ eligible major mapping
// ---------------------------------------------------------------------------
$insCM = $pdo->prepare('INSERT IGNORE INTO CourseMajor (course_id, major_id) VALUES (:cid, :mid)');
$mapCourse = function (int $courseId, array $majorCodes) use ($insCM, $majorIds, $ALL_MAJORS, &$stats): void {
    $targets = in_array('*', $majorCodes, true) ? $ALL_MAJORS : $majorCodes;
    foreach (array_unique($targets) as $mc) {
        if (isset($majorIds[$mc])) {
            $insCM->execute([':cid' => $courseId, ':mid' => $majorIds[$mc]]);
            if ($insCM->rowCount() > 0) $stats['course_major_rows']++;
        }
    }
};
foreach ($COURSES as $row) {
    $mapCourse($courseIdByCode[$row[0]], $row[5]);
}
foreach ($EXISTING_ELIGIBLE as $code => $majors) {
    if (isset($courseIdByCode[$code])) $mapCourse($courseIdByCode[$code], $majors);
}

// ---------------------------------------------------------------------------
// 3. Curriculum ↔ course mappings (all 41 curricula)
// ---------------------------------------------------------------------------
$curricula = $pdo->query('SELECT curriculum_id, major_id FROM Curriculum WHERE major_id IS NOT NULL')->fetchAll();
$insCC = $pdo->prepare(
    "INSERT IGNORE INTO CurriculumCourse (curriculum_id, course_id, course_type, semester_recommended, is_required)
     VALUES (:cur, :cid, 'Elective', NULL, 0)"
);
$courseIdsForMajor = $pdo->prepare('SELECT course_id FROM CourseMajor WHERE major_id = :mid');
foreach ($curricula as $cur) {
    $courseIdsForMajor->execute([':mid' => (int)$cur['major_id']]);
    foreach ($courseIdsForMajor->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        $insCC->execute([':cur' => (int)$cur['curriculum_id'], ':cid' => (int)$cid]);
        if ($insCC->rowCount() > 0) $stats['curriculum_rows']++;
    }
}

// ---------------------------------------------------------------------------
// 4. Lecturers + accounts
// ---------------------------------------------------------------------------
$lecturerPools = [];
$deptOfLecturer = [];
$findLecturer = $pdo->prepare('SELECT lecturer_id, department_id FROM Lecturer WHERE email = :e LIMIT 1');
$insertLecturer = $pdo->prepare(
    'INSERT INTO Lecturer (full_name, email, phone, department_id, status, academic_title, position_title, specialization)
     VALUES (:name, :email, :phone, :dept, \'Active\', :title, :position, :spec)'
);
$findAccount = $pdo->prepare('SELECT account_id FROM Account WHERE username = :u LIMIT 1');
$insertAccount = $pdo->prepare(
    "INSERT INTO Account (username, password_hash, role, lecturer_id, is_active)
     VALUES (:u, :p, 'Lecturer', :lid, 1)"
);
$passwordHash = password_hash('password', PASSWORD_BCRYPT);
$deptCounters = [];
$phoneSeq = 981000000;

foreach ($LECTURERS as $idx => $lec) {
    [$name, $deptCode, $title, $position, $spec] = $lec;
    $deptCounters[$deptCode] = ($deptCounters[$deptCode] ?? 0) + 1;
    $local = sprintf('gv.%s%02d', strtolower($deptCode), $deptCounters[$deptCode]);
    $email = $local . '@uniportal.edu.vn';

    $findLecturer->execute([':e' => $email]);
    $existing = $findLecturer->fetch();
    if ($existing) {
        $lid = (int)$existing['lecturer_id'];
        $pdo->prepare(
            'UPDATE Lecturer SET academic_title = COALESCE(academic_title, :t),
                    position_title = COALESCE(position_title, :p),
                    specialization = COALESCE(specialization, :s)
             WHERE lecturer_id = :id'
        )->execute([':t' => $title, ':p' => $position, ':s' => $spec, ':id' => $lid]);
    } else {
        do {
            $phone = (string)(++$phoneSeq);
            $qPhone = $pdo->prepare('SELECT 1 FROM Lecturer WHERE phone = :ph LIMIT 1');
            $qPhone->execute([':ph' => $phone]);
        } while ($qPhone->fetch());
        $insertLecturer->execute([
            ':name' => $name, ':email' => $email, ':phone' => $phone,
            ':dept' => $deptIds[$deptCode] ?? null, ':title' => $title,
            ':position' => $position, ':spec' => $spec,
        ]);
        $lid = (int)$pdo->lastInsertId();
        $stats['lecturers_inserted']++;
    }
    $lecturerPools[$deptCode][] = $lid;
    $deptOfLecturer[$lid] = $deptCode;

    $findAccount->execute([':u' => $local]);
    if (!$findAccount->fetch()) {
        $insertAccount->execute([':u' => $local, ':p' => $passwordHash, ':lid' => $lid]);
        $stats['accounts_inserted']++;
    }
}

// ---------------------------------------------------------------------------
// 5. Current-semester sections for the new catalog (one section per course)
// ---------------------------------------------------------------------------
$DAYS = [2, 3, 4, 5, 6];
$SLOTS = [['07:30:00','09:00:00'], ['09:15:00','10:45:00'], ['13:00:00','14:30:00'], ['15:00:00','16:30:00']];
$ROOMS = ['A1.101','A1.102','A2.201','A2.202','B3.301','B3.302','C1.101','C1.102','D1.201','D1.202'];
$busy = [];
$poolPtr = [];
$pickLecturer = function (string $deptCode, string $slotKey) use (&$lecturerPools, &$busy, &$poolPtr): int {
    $pool = $lecturerPools[$deptCode] ?? [];
    if (!$pool) {
        foreach ($lecturerPools as $p) { $pool = array_merge($pool, $p); }
    }
    $n = count($pool);
    if ($n === 0) return 0;
    $start = $poolPtr[$deptCode] ?? 0;
    for ($k = 0; $k < $n; $k++) {
        $lid = $pool[($start + $k) % $n];
        if (empty($busy[$lid][$slotKey])) {
            $busy[$lid][$slotKey] = true;
            $poolPtr[$deptCode] = ($start + $k + 1) % $n;
            return $lid;
        }
    }
    $lid = $pool[$start % $n];
    $busy[$lid][$slotKey] = true;
    return $lid;
};

$findSection = $pdo->prepare('SELECT section_id FROM ClassSection WHERE section_code=:code AND semester_id=:sem LIMIT 1');
$insertSection = $pdo->prepare(
    "INSERT INTO ClassSection (course_id, semester_id, lecturer_id, section_code, schedule, room, capacity, min_enrollment, max_enrollment, section_status)
     VALUES (:cid, :sem, :lid, :code, NULL, NULL, 70, 30, 70, 'Pending')"
);
$newSectionIds = [];
$i = 0;
foreach ($COURSES as $row) {
    $code = $row[0];
    $sectionCode = $code . '-01';
    $findSection->execute([':code' => $sectionCode, ':sem' => $SEMESTER_ID]);
    $sid = (int)($findSection->fetchColumn() ?: 0);
    if ($sid > 0) { $newSectionIds[] = $sid; $i++; continue; }

    $deptCode = $row[3];
    $lid = $pickLecturer($deptCode, ($DAYS[$i % 5]) . '-' . (intdiv($i, 5) % 4));
    if ($lid === 0) { $stats['errors'][] = "No lecturer for $code"; continue; }
    $insertSection->execute([
        ':cid' => $courseIdByCode[$code], ':sem' => $SEMESTER_ID, ':lid' => $lid,
        ':code' => $sectionCode,
    ]);
    $newSectionIds[] = (int)$pdo->lastInsertId();
    $stats['sections_inserted']++;
    $i++;
}

// ---------------------------------------------------------------------------
// 6. Teaching schedules + exact session generation for new sections
// ---------------------------------------------------------------------------
$hasTs = $pdo->prepare('SELECT schedule_id FROM TeachingSchedule WHERE section_id = :sid LIMIT 1');
$secInfo = $pdo->prepare(
    'SELECT cs.section_id, cs.course_id, cs.section_code, c.course_code, c.credits
     FROM ClassSection cs JOIN Course c ON c.course_id = cs.course_id WHERE cs.section_id = :sid'
);
$order = 0;
foreach ($newSectionIds as $sid) {
    $hasTs->execute([':sid' => $sid]);
    if ($hasTs->fetchColumn()) { $order++; continue; }
    $secInfo->execute([':sid' => $sid]);
    $sec = $secInfo->fetch();
    if (!$sec) { $order++; continue; }

    $courseIndex = null;
    foreach ($COURSES as $ix => $cr) { if ($cr[0] === $sec['course_code']) { $courseIndex = $ix; break; } }
    if ($courseIndex === null) { $courseIndex = $order; }
    $day = $DAYS[$courseIndex % 5];
    $slot = $SLOTS[intdiv($courseIndex, 5) % 4];
    $room = $ROOMS[intdiv($courseIndex, 10) % 10];
    $lecturerId = (int)$pdo->query('SELECT lecturer_id FROM ClassSection WHERE section_id=' . (int)$sid)->fetchColumn();

    $res = TeachingSchedule::saveSchedule([
        'section_id' => $sid,
        'lecturer_id' => $lecturerId,
        'day_of_week' => $day,
        'start_time' => $slot[0],
        'end_time' => $slot[1],
        'room' => $room,
        'effective_start' => '2026-09-07',
        'effective_end' => '2027-01-22',
        'expected_sessions' => '',
    ], $ADMIN_ACCOUNT_ID);
    if ($res['ok']) $stats['schedules_saved']++;
    else $stats['errors'][] = $sec['course_code'] . ': ' . $res['message'];
    $order++;
}

// ---------------------------------------------------------------------------
// 7. Normalise the four legacy current-semester sections to exactly 15 sessions
// ---------------------------------------------------------------------------
$legacy = [
    35 => [6, '07:30:00', '09:00:00', 'B2.301'],
    36 => [6, '09:15:00', '10:45:00', 'B1.401'],
    37 => [6, '13:00:00', '14:30:00', 'C2.201'],
    38 => [6, '07:30:00', '09:00:00', 'B1.106'],
];
foreach ($legacy as $sid => [$dow, $st, $en, $room]) {
    $sec = $pdo->prepare(
        'SELECT cs.section_id, cs.lecturer_id, cs.section_code, c.credits
         FROM ClassSection cs JOIN Course c ON c.course_id=cs.course_id WHERE cs.section_id=:sid LIMIT 1'
    );
    $sec->execute([':sid' => $sid]);
    $s = $sec->fetch();
    if (!$s) continue;
    $target = (int)$s['credits'] * TeachingSchedule::SESSIONS_PER_CREDIT;

    $pdo->prepare(
        'INSERT INTO TeachingSchedule
            (section_id, semester_id, lecturer_id, day_of_week, start_time, end_time, room,
             effective_start, effective_end, expected_sessions, status, created_by)
         SELECT :sid, :sem, :lid, :dow, :st, :en, :room, :es, :ee, :exp, \'Active\', :by
         WHERE NOT EXISTS (SELECT 1 FROM TeachingSchedule WHERE section_id = :sid2)'
    )->execute([
        ':sid' => $sid, ':sem' => $SEMESTER_ID, ':lid' => (int)$s['lecturer_id'],
        ':dow' => $dow, ':st' => $st, ':en' => $en, ':room' => $room,
        ':es' => '2026-09-07', ':ee' => '2027-01-22', ':exp' => $target,
        ':by' => $ADMIN_ACCOUNT_ID, ':sid2' => $sid,
    ]);
    $tsId = (int)$pdo->query('SELECT schedule_id FROM TeachingSchedule WHERE section_id=' . (int)$sid)->fetchColumn();
    $pdo->prepare(
        "UPDATE ClassSection SET study_days=:dow, start_time=:st, end_time=:en, room=:room,
                schedule=:txt WHERE section_id=:sid"
    )->execute([
        ':dow' => (string)$dow, ':st' => $st, ':en' => $en, ':room' => $room,
        ':txt' => 'Thứ 7 ' . substr($st,0,5) . '-' . substr($en,0,5), ':sid' => $sid,
    ]);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM AttendanceSession WHERE section_id=:sid AND status <> 'Cancelled'");
    $maxNoStmt = $pdo->prepare('SELECT COALESCE(MAX(session_no),0) FROM AttendanceSession WHERE section_id=:sid');
    $insertSess = $pdo->prepare(
        "INSERT INTO AttendanceSession
            (section_id, schedule_id, lecturer_id, session_no, session_date, start_time, end_time, room,
             topic, session_type, status)
         VALUES (:sid, :ts, :lid, :no, :date, :st, :en, :room, :topic, :type, 'Scheduled')"
    );
    $countStmt->execute([':sid' => $sid]);
    $count = (int)$countStmt->fetchColumn();
    $maxNoStmt->execute([':sid' => $sid]);
    $maxNo = (int)$maxNoStmt->fetchColumn();
    $existsDate = $pdo->prepare('SELECT 1 FROM AttendanceSession WHERE section_id=:sid AND session_date=:d LIMIT 1');

    $d = new DateTimeImmutable('2026-11-21');
    $limit = new DateTimeImmutable('2027-01-22');
    while ($count < $target && $d <= $limit) {
        $date = $d->format('Y-m-d');
        $existsDate->execute([':sid' => $sid, ':d' => $date]);
        if (!$existsDate->fetch()) {
            $insertSess->execute([
                ':sid' => $sid, ':ts' => $tsId, ':lid' => (int)$s['lecturer_id'], ':no' => ++$maxNo,
                ':date' => $date, ':st' => $st, ':en' => $en, ':room' => $room,
                ':topic' => 'Buổi học chính thức', ':type' => 'Regular',
            ]);
            $count++;
            $stats['sessions_added']++;
        }
        $d = $d->modify('+7 days');
        if ($count >= $target) break;
    }
    $makeupDates = ['2026-11-19', '2026-11-26', '2026-12-03'];
    foreach ($makeupDates as $date) {
        if ($count >= $target) break;
        $existsDate->execute([':sid' => $sid, ':d' => $date]);
        if (!$existsDate->fetch()) {
            $insertSess->execute([
                ':sid' => $sid, ':ts' => $tsId, ':lid' => (int)$s['lecturer_id'], ':no' => ++$maxNo,
                ':date' => $date, ':st' => $st, ':en' => $en, ':room' => $room,
                ':topic' => 'Buổi học bù', ':type' => 'Makeup',
            ]);
            $count++;
            $stats['sessions_added']++;
        }
    }
}

// ---------------------------------------------------------------------------
// 8. Open every current-semester section in the active registration period
// ---------------------------------------------------------------------------
$periodId = (int)$pdo->query(
    "SELECT rp.period_id FROM registrationperiod rp JOIN Semester sem ON sem.semester_id=rp.semester_id
     WHERE rp.semester_id=7 AND rp.status='Open' ORDER BY rp.start_at DESC LIMIT 1"
)->fetchColumn();
if ($periodId > 0) {
    $sections = $pdo->query("SELECT section_id FROM ClassSection WHERE semester_id=7 AND section_status <> 'Cancelled'")->fetchAll(PDO::FETCH_COLUMN);
    $insRps = $pdo->prepare('INSERT IGNORE INTO RegistrationPeriodSection (period_id, section_id) VALUES (:p, :s)');
    foreach ($sections as $sid) {
        $insRps->execute([':p' => $periodId, ':s' => (int)$sid]);
        if ($insRps->rowCount() > 0) $stats['periods_mapped']++;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
$totCourses = (int)$pdo->query('SELECT COUNT(*) FROM Course')->fetchColumn();
$totLecturers = (int)$pdo->query('SELECT COUNT(*) FROM Lecturer')->fetchColumn();
$totSections = (int)$pdo->query('SELECT COUNT(*) FROM ClassSection WHERE semester_id=7')->fetchColumn();
$totSchedules = (int)$pdo->query('SELECT COUNT(*) FROM TeachingSchedule WHERE semester_id=7')->fetchColumn();

echo "=== Seed v14 summary ===\n";
foreach ($stats as $k => $v) {
    if ($k === 'errors') continue;
    echo str_pad($k, 24) . ": $v\n";
}
echo "total courses           : $totCourses\n";
echo "total lecturers         : $totLecturers\n";
echo "semester 7 sections     : $totSections\n";
echo "semester 7 schedules    : $totSchedules\n";
if ($stats['errors']) {
    echo "warnings (" . count($stats['errors']) . "):\n - " . implode("\n - ", array_slice($stats['errors'], 0, 30)) . "\n";
}
