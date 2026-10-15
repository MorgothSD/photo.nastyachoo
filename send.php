<?php
/**
 * Приём заявок с сайта photo-nastyachoo.ru
 * Работает на обычном PHP-хостинге (reg.ru, Timeweb и т.п.)
 *
 * Что делает:
 *   1. принимает POST с формы;
 *   2. отсекает ботов по honeypot-полю и простому ограничению частоты;
 *   3. записывает заявку в CSV-файл — резервная копия на случай,
 *      если письмо не дойдёт;
 *   4. отправляет письмо на вашу почту;
 *   5. возвращает JSON, чтобы сайт понял — сохранилось или нет.
 *
 * ── ЧТО НАСТРОИТЬ ПЕРЕД ЗАЛИВКОЙ ──────────────────────────
 *   MAIL_TO   — куда присылать заявки
 *   MAIL_FROM — адрес-отправитель НА ВАШЕМ ДОМЕНЕ.
 *               Почтовый ящик нужно создать в панели хостинга,
 *               иначе письма будут уходить в спам.
 */

// ═══ НАСТРОЙКИ ═══
const MAIL_TO    = 'ваша-почта@example.com';
const MAIL_FROM  = 'noreply@photo-nastyachoo.ru';
const SITE_NAME  = 'photo.nastyachoo';
const LOG_FILE   = __DIR__ . '/leads.csv';
const RATE_LIMIT = 30;   // секунд между заявками с одного IP
// ═════════════════

header('Content-Type: application/json; charset=utf-8');

function reply(bool $ok, string $msg = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(false, 'Метод не поддерживается', 405);
}

// ── Honeypot: поле скрыто от людей, боты его заполняют
if (!empty($_POST['bot-field'])) {
    reply(true);   // молча «принимаем», чтобы бот не подбирал обход
}

// ── Ограничение частоты по IP
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock = sys_get_temp_dir() . '/lead_' . md5($ip);
if (file_exists($lock) && (time() - filemtime($lock)) < RATE_LIMIT) {
    reply(false, 'Слишком часто. Подождите немного и попробуйте снова.', 429);
}
touch($lock);

// ── Читаем и чистим поля
function clean(string $key, int $max = 500): string {
    $v = trim((string)($_POST[$key] ?? ''));
    $v = str_replace(["\r", "\n", "\0"], ' ', $v);   // защита от инъекции заголовков
    $v = mb_substr($v, 0, $max);
    return $v;
}

$name    = clean('name', 100);
$phone   = clean('phone', 30);
$type    = clean('shoot_type', 60);
$date    = clean('date', 20);
$package = clean('package', 40);
$comment = mb_substr(trim((string)($_POST['comment'] ?? '')), 0, 1000);
$consent = !empty($_POST['consent']);

// ── Проверка обязательных полей
$digits = preg_replace('/\D/', '', $phone);
if (mb_strlen($name) < 2) {
    reply(false, 'Укажите, как к вам обращаться', 422);
}
if (mb_strlen($digits) < 10) {
    reply(false, 'Укажите корректный номер телефона', 422);
}
if (!$consent) {
    reply(false, 'Нужно согласие на обработку персональных данных', 422);
}

// ── Резервная запись в файл: даже если почта не работает, заявка не потеряется
$row = [
    date('Y-m-d H:i:s'),
    $name, $phone, $type, $date, $package,
    str_replace(["\r", "\n"], ' ', $comment),
    $ip,
];
if ($fh = @fopen(LOG_FILE, 'a')) {
    if (filesize(LOG_FILE) === 0) {
        fwrite($fh, "\xEF\xBB\xBF");   // BOM, чтобы Excel не ломал кириллицу
        fputcsv($fh, ['Дата','Имя','Телефон','Тип съёмки','Дата съёмки','Пакет','Комментарий','IP'], ';');
    }
    fputcsv($fh, $row, ';');
    fclose($fh);
    @chmod(LOG_FILE, 0600);
}

// ── Письмо
$lines = [
    'Новая заявка с сайта ' . SITE_NAME,
    '',
    'Имя:        ' . $name,
    'Телефон:    ' . $phone,
];
if ($type)    $lines[] = 'Тип съёмки: ' . $type;
if ($package) $lines[] = 'Пакет:      ' . $package;
if ($date)    $lines[] = 'Желаемая дата: ' . $date;
if ($comment) { $lines[] = ''; $lines[] = 'Комментарий:'; $lines[] = $comment; }
$lines[] = '';
$lines[] = 'Отправлено: ' . date('d.m.Y H:i');

$body    = implode("\n", $lines);
$subject = 'Заявка с сайта: ' . $name . ' — ' . $phone;

$headers = implode("\r\n", [
    'From: ' . SITE_NAME . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . MAIL_FROM,
    'Content-Type: text/plain; charset=UTF-8',
    'X-Mailer: PHP/' . phpversion(),
]);

$sent = @mail(MAIL_TO, $subject, $body, $headers);

// Заявка уже записана в CSV, поэтому считаем успехом даже при сбое почты
reply(true, $sent ? 'Заявка отправлена' : 'Заявка сохранена');
