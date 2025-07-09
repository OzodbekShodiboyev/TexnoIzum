<?php
$token = 'Token'; // <-- BOT TOKEN QO'Y
$apiUrl = "https://api.telegram.org/bot$token/";
$requiredChannel = '@'; // <-- KANAL USERNAME QO'Y

// MySQL ulanish
$servername = "localhost";
$username = "*";
$password = "*";
$dbname = "*";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Kiritilgan so‘rovni olish
$input = file_get_contents('php://input');
$update = json_decode($input, true);

$chatId = $update['message']['chat']['id'] ?? null;
$message = $update['message']['text'] ?? '';
$callbackQuery = $update['callback_query'] ?? null;

if ($chatId && $message) {
    if (strpos($message, '/start') === 0) {
        $referralCode = trim(substr($message, 6)); // /start abc123
        $stmt = $conn->prepare("SELECT referral_code FROM users WHERE telegram_id = ?");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $stmt->bind_result($existingReferralCode);
        $stmt->fetch();
        $stmt->close();

        // Obuna tekshiruvi
        if (!isUserSubscribed($chatId, $requiredChannel)) {
            // foydalanuvchi bazada yo'q bo‘lsa — yaratamiz va referral code'ni vaqtincha saqlaymiz
            if (!$existingReferralCode) {
                $newCode = bin2hex(random_bytes(5));
                $stmt = $conn->prepare("INSERT INTO users (telegram_id, referral_code, waiting_verification) VALUES (?, ?, ?)");
                $stmt->bind_param("iss", $chatId, $newCode, $referralCode);
                $stmt->execute();
                $stmt->close();
            } else {
                // eski user bo‘lsa, shunchaki referralni saqlaymiz
                $stmt = $conn->prepare("UPDATE users SET waiting_verification = ? WHERE telegram_id = ?");
                $stmt->bind_param("si", $referralCode, $chatId);
                $stmt->execute();
                $stmt->close();
            }

            $response = "📢 Botdan foydalanishdan oldin kanalga obuna bo‘lishingiz kerak: $requiredChannel\n\n👇 Obuna bo‘lsangiz, quyidagi tugmani bosing.";
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => "✅ Obunani tekshirish", 'callback_data' => 'check_subscription']]
                ]
            ];
            sendMessage($chatId, $response, $keyboard);
            exit;
        }

        // obunadan o‘tgan bo‘lsa
        if ($existingReferralCode) {
            // $response = "Siz allaqachon ro‘yxatdan o‘tgansiz.\nReferal havolangiz: https://t.me/texnoizum_bot?start=$existingReferralCode";
             $response = "Tabriklayman, siz allaqachon bepul darslikga mufaqqiyatli ro'yxatdan o'tgansiz🥳

<b>Ushbu bot orqali do'stlaringiz va yaqinlaringizni taklif qilgan holda bir qancha qimmatli sovg'alarni qo'lga kiritishingiz mumkin🥰 

🎁Balki aynan siz - xorijga bepul sayohat yo'llanmasi, noutbuk va  zamonaviy telefonni qo'lga kiritishingiz mumkin.</b>

<b>Qatnashish sharti haqida to'liqroq bilish uchun -</b> \"Do'stlarni taklif qilish\" <b>tugmasi ustiga bosing⚡️</b>
<b>Agar siz taklif qilgan do'stlaringiz sonini bilishni istasangiz</b> \"Mening hisobim\" , <b>umumiy reytingni bilish uchun</b> \"Reyting\" <b>tugmasi ustiga bosishingiz mumkin😉</b>";
        } else {
            $myCode = bin2hex(random_bytes(5));
            $stmt = $conn->prepare("INSERT INTO users (telegram_id, referral_code, referred_by) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $chatId, $myCode, $referralCode ?: null);
            $stmt->execute();
            $stmt->close();

            if (!empty($referralCode)) {
                $stmt = $conn->prepare("UPDATE users SET points = points + 1 WHERE referral_code = ?");
                $stmt->bind_param("s", $referralCode);
                $stmt->execute();
                $stmt->close();

                $stmt = $conn->prepare("SELECT telegram_id FROM users WHERE referral_code = ?");
                $stmt->bind_param("s", $referralCode);
                $stmt->execute();
                $stmt->bind_result($referrerId);
                $stmt->fetch();
                $stmt->close();

                sendMessage($referrerId, "🎉 Sizning referal orqali do‘stingiz obuna bo‘ldi. Sizga +1 ball berildi!");
            }

            // $response = "Botga hush kelibsiz!\nReferal havolangiz: https://t.me/texnoizum_bot?start=$myCode";
             $response = "Tabriklayman, siz allaqachon bepul darslikga mufaqqiyatli ro'yxatdan o'tgansiz🥳

<b>Ushbu bot orqali do'stlaringiz va yaqinlaringizni taklif qilgan holda bir qancha qimmatli sovg'alarni qo'lga kiritishingiz mumkin🥰 

🎁Balki aynan siz - xorijga bepul sayohat yo'llanmasi, noutbuk va  zamonaviy telefonni qo'lga kiritishingiz mumkin.</b>

<b>Qatnashish sharti haqida to'liqroq bilish uchun -</b> \"Do'stlarni taklif qilish\" <b>tugmasi ustiga bosing⚡️</b>
<b>Agar siz taklif qilgan do'stlaringiz sonini bilishni istasangiz</b> \"Mening hisobim\" , <b>umumiy reytingni bilish uchun</b> \"Reyting\" <b>tugmasi ustiga bosishingiz mumkin😉</b>";
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => "Do‘stlarni taklif qilish", 'callback_data' => 'invite_friends'],
                    ['text' => "Ballarni ko‘rish", 'callback_data' => 'check_points']
                ],
                [['text' => "Reyting", 'callback_data' => 'rating']]
            ]
        ];

        $photoUrl = "https://t.me/files_online/17"; // Rasm URL ni o'zgartirish mumkin

        sendPhoto($chatId, $photoUrl, $response, 'HTML', $keyboard);
        // sendMessage($chatId, $response, $keyboard, 'HTML');
    }
} elseif ($callbackQuery) {
    $callbackData = $callbackQuery['data'];
    $callbackChatId = $callbackQuery['message']['chat']['id'];

    if ($callbackData === 'check_subscription') {
        if (!isUserSubscribed($callbackChatId, $requiredChannel)) {
            sendMessage($callbackChatId, "Afsuski, siz hali kanalga obuna bo’lmadingiz🤷‍♀️

Obuna bo’lganingizdan keyingina bepul darslikka ega bo’lishingiz va sovg’alar haqida ma’lumot olishingiz mumkin⚡️\n\nObuna bo‘ling: $requiredChannel");
        } else {
            $stmt = $conn->prepare("SELECT waiting_verification, referred_by FROM users WHERE telegram_id = ?");
            $stmt->bind_param("i", $callbackChatId);
            $stmt->execute();
            $stmt->bind_result($waitingReferralCode, $referredBy);
            $stmt->fetch();
            $stmt->close();

            if ($referredBy || !$waitingReferralCode) {
                sendMessage($callbackChatId, "✅ Obuna tasdiqlandi. Botdan foydalanishingiz mumkin.");
                $keyboard = [
                    'inline_keyboard' => [
                        [
                            ['text' => "Do‘stlarni taklif qilish", 'callback_data' => 'invite_friends'],
                            ['text' => "Ballarni ko‘rish", 'callback_data' => 'check_points']
                        ],
                        [['text' => "Reyting", 'callback_data' => 'rating']]
                    ]
                ];
                sendMessage($callbackChatId, "Tabriklayman, siz allaqachon bepul darslikga mufaqqiyatli ro'yxatdan o'tgansiz🥳

<b>Ushbu bot orqali do'stlaringiz va yaqinlaringizni taklif qilgan holda bir qancha qimmatli sovg'alarni qo'lga kiritishingiz mumkin🥰 

🎁Balki aynan siz - xorijga bepul sayohat yo'llanmasi, noutbuk va  zamonaviy telefonni qo'lga kiritishingiz mumkin.</b>

<b>Qatnashish sharti haqida to'liqroq bilish uchun -</b> \"Do'stlarni taklif qilish\" <b>tugmasi ustiga bosing⚡️</b>
<b>Agar siz taklif qilgan do'stlaringiz sonini bilishni istasangiz</b> \"Mening hisobim\" , <b>umumiy reytingni bilish uchun</b> \"Reyting\" <b>tugmasi ustiga bosishingiz mumkin😉</b>", $keyboard, 'HTML');
            } else {
                // Referer ID topish
                $stmt = $conn->prepare("SELECT telegram_id FROM users WHERE referral_code = ?");
                $stmt->bind_param("s", $waitingReferralCode);
                $stmt->execute();
                $stmt->bind_result($referrerId);
                $stmt->fetch();
                $stmt->close();

                if ($referrerId) {
                    $stmt = $conn->prepare("UPDATE users SET referred_by = ?, waiting_verification = NULL WHERE telegram_id = ?");
                    $stmt->bind_param("si", $waitingReferralCode, $callbackChatId);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("UPDATE users SET points = points + 1 WHERE referral_code = ?");
                    $stmt->bind_param("s", $waitingReferralCode);
                    $stmt->execute();
                    $stmt->close();

                    sendMessage($referrerId, "🔥Sizning referal kod orqali do'stingiz botga qo'shildi va sizga +1 ball berildi!

Yanada ko’proq do’stlaringizni taklif qilish orqali sovg’alarga ega bo’ling😉");
                }

                sendMessage($callbackChatId, "✅ Obuna tasdiqlandi! Endi botdan foydalanishingiz mumkin.");
                sendMessage($callbackChatId, "Tabriklayman, siz allaqachon bepul darslikga mufaqqiyatli ro'yxatdan o'tgansiz🥳

<b>Ushbu bot orqali do'stlaringiz va yaqinlaringizni taklif qilgan holda bir qancha qimmatli sovg'alarni qo'lga kiritishingiz mumkin🥰 

🎁Balki aynan siz - xorijga bepul sayohat yo'llanmasi, noutbuk va  zamonaviy telefonni qo'lga kiritishingiz mumkin.</b>

<b>Qatnashish sharti haqida to'liqroq bilish uchun -</b> \"Do'stlarni taklif qilish\" <b>tugmasi ustiga bosing⚡️</b>
<b>Agar siz taklif qilgan do'stlaringiz sonini bilishni istasangiz</b> \"Mening hisobim\" , <b>umumiy reytingni bilish uchun</b> \"Reyting\" <b>tugmasi ustiga bosishingiz mumkin😉</b>", $keyboard, 'HTML');
            }
        }
    } elseif ($callbackData === 'invite_friends') {
        $stmt = $conn->prepare("SELECT referral_code FROM users WHERE telegram_id = ?");
        $stmt->bind_param("i", $callbackChatId);
        $stmt->execute();
        $stmt->bind_result($refCode);
        $stmt->fetch();
        $stmt->close();
        // sendMessage($callbackChatId, "Sizning referal havolangiz:\nhttps://t.me/texnoizum_bot?start=$refCode");
        $caption = "🎁<b>BEPUL</b> Chet el sayohati, zamonaviy noutbuk va telefonga ega bo’ling!\n\n
Sun’iy intellekt va zamonaviy kasblar orqali 1000\$+ daromadga chiqishni, o’zingizni birinchi blogingizni ochishni bepul o’rgatamiz⚡️\n\n
<b>😉Ushbu bepul darsda siz quyidagilarni o'rganasiz:\n
✅ ChatGPT yordamida professional fotosuratlarni tayyorlash va sotish\n
✅ Sun’iy intellektlar yordamida trend videolar yasash\n
✅ Zamonaviy kasblar orqali daromad qilish\n\n
🎉 Undan tashqari Kanalimiz a‘zolari uchun qo'shimcha maxsus sovg‘alar ham bor.\n
🥳 Chet el sayohati uchun yo’llanma, zamonaviy noutbuk va telefon aynan sizni kutmoqda\n
Hoziroq ishtirok eting va yaqinlaringizni ham taklif qiling: 👇🏻\n</b>
https://t.me/texnoizum_bot?start=$refCode";

        $photoUrl = "https://t.me/files_online/16"; // Rasm URL ni o'zgartirish mumkin

        sendPhoto($callbackChatId, $photoUrl, $caption, 'HTML');
        sendMessage($callbackChatId, "⬆️

Bu sizning shaxsiy linkingiz!

🎁 Uni do’stlaringiz, yaqinlaringizga jo'nating va o'z sovg'angizni qo'lga kiriting!

Mening hisobim bo'limi orqali ballaringizni ko'rishingiz mumkin!", null, 'HTML');
    } elseif ($callbackData === 'check_points') {
        $stmt = $conn->prepare("SELECT points FROM users WHERE telegram_id = ?");
        $stmt->bind_param("i", $callbackChatId);
        $stmt->execute();
        $stmt->bind_result($points);
        $stmt->fetch();
        $stmt->close();
        sendMessage($callbackChatId, "$points ta yaqinlaringiz botga a'zo bo'lishdi

<b>Sovg'alarni qo'lga kiritish uchun  ko'proq do'stlaringizni taklif qiling⚡️</b>", NULL, 'HTML');
    } elseif ($callbackData === 'rating') {
        $stmt = $conn->prepare("SELECT telegram_id, points FROM users ORDER BY points DESC LIMIT 10");
        $stmt->execute();
        $stmt->bind_result($userId, $pts);
        $msg = "🏆Top 10 eng ko’p do’stlarini qo’shgan foydalanuvchilar:\n";
        $rank = 1;
        while ($stmt->fetch()) {
            $msg .= "$rank. [$userId](tg://user?id=$userId) - $pts ball\n";
            $rank++;
        }
        $stmt->close();
        sendMessage($callbackChatId, $msg, null, 'Markdown');
    }
}

// FUNCTIONS

function isUserSubscribed($userId, $channel)
{
    global $apiUrl;

    $url = $apiUrl . "getChatMember?chat_id=$channel&user_id=$userId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $res = json_decode($response, true);

    // Debug maqsadida yozib qo'yamiz
    file_put_contents("debug_getchatmember.txt", print_r($res, true));

    if (!isset($res['result']['status'])) return false;
    return in_array($res['result']['status'], ['member', 'administrator', 'creator']);
}

function sendMessage($chatId, $message, $keyboard = null, $parseMode = null)
{
    global $apiUrl;

    $data = [
        'chat_id' => $chatId,
        'text' => $message
    ];

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . "sendMessage");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    $result = curl_exec($ch);
    curl_close($ch);

    return $result;
}
function sendPhoto($chatId, $photoUrl, $caption, $parseMode = null, $keyboard = null)
{
    global $apiUrl;

    $data = [
        'chat_id' => $chatId,
        'photo' => $photoUrl,
        'caption' => $caption
    ];

    if ($parseMode) {
        $data['parse_mode'] = $parseMode;
    }

    if ($keyboard) {
        $data['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . "sendPhoto");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL xatosi: " . curl_error($ch));
    }
    curl_close($ch);
}

