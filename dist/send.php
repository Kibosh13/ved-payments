<?php

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond($status, $ok, $message)
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function field($name, $maxLength)
{
    $value = trim((string)(isset($_POST[$name]) ? $_POST[$name] : ''));
    $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    $value = $cleaned === null ? '' : $cleaned;
    if ($value === '' || mb_strlen($value) > $maxLength) {
        respond(422, false, 'Проверьте заполнение обязательных полей.');
    }
    return $value;
}

if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    respond(405, false, 'Метод запроса не поддерживается.');
}

if (!empty($_POST['website'])) {
    respond(200, true, 'Заявка отправлена.');
}

$origin = strtolower((string)(isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : ''));
if ($origin !== '' && !in_array($origin, ['http://vedpayhelp.ru', 'http://www.vedpayhelp.ru', 'https://vedpayhelp.ru', 'https://www.vedpayhelp.ru'], true)) {
    respond(403, false, 'Запрос отклонён. Обновите страницу и попробуйте снова.');
}

$rateFile = sys_get_temp_dir() . '/vedpayhelp-' . hash('sha256', (string)(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown'));
$lastSent = is_file($rateFile) ? (int)file_get_contents($rateFile) : 0;
if ($lastSent > time() - 30) {
    respond(429, false, 'Заявка уже отправляется. Повторите попытку через минуту.');
}

$company = field('company', 160);
$normalizedInn = preg_replace('/\D+/', '', field('inn', 12));
$inn = $normalizedInn === null ? '' : $normalizedInn;
if (!preg_match('/^(\d{10}|\d{12})$/', $inn)) {
    respond(422, false, 'Укажите ИНН из 10 или 12 цифр.');
}
$amount = field('amount', 80);
$currency = field('currency', 20);
$country = field('country', 100);
$goods = field('goods', 1500);
$contacts = field('contacts', 250);
if ((isset($_POST['consent']) ? $_POST['consent'] : '') !== 'on') {
    respond(422, false, 'Необходимо согласие на обработку персональных данных.');
}

$subjectText = 'Заявка на международный платёж — ' . $company;
$subject = '=?UTF-8?B?' . base64_encode($subjectText) . '?=';
$message = implode("\r\n", [
    'Новая заявка с сайта vedpayhelp.ru',
    '',
    'Компания: ' . $company,
    'ИНН: ' . $inn,
    'Сумма: ' . $amount . ' ' . $currency,
    'Страна: ' . $country,
    'ТН ВЭД / услуга: ' . $goods,
    'Контакты: ' . $contacts,
    '',
    'Пользователь подтвердил согласие на обработку персональных данных.',
    'Дата: ' . date('d.m.Y H:i:s T'),
]);

$headers = implode("\r\n", [
    'From: VED Payments <requests@vedpayhelp.ru>',
    'Sender: requests@vedpayhelp.ru',
    'Reply-To: requests@vedpayhelp.ru',
    'Date: ' . date(DATE_RFC2822),
    'Message-ID: <' . sha1(uniqid('', true)) . '@vedpayhelp.ru>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
]);

if (!mail('requests@vedpayhelp.ru', $subject, $message, $headers, '-f requests@vedpayhelp.ru')) {
    respond(500, false, 'Сервис отправки временно недоступен.');
}

@file_put_contents($rateFile, (string)time(), LOCK_EX);
respond(200, true, 'Заявка отправлена.');
