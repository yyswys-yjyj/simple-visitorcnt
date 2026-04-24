<?php
/**
 * 访问量统计器 - 支持路径独立 tag 和显示名称
 * 每个路径可在 data.json 中配置 tag（如 "tag": "logo.png"）和 display_name（如 "display_name": "我的文章"）
 */

define('DATA_FILE', __DIR__ . '/data.json');
define('FONT_PATH', __DIR__ . '/SiYuanHeTi.otf');
define('FONT_FALLBACK', __DIR__ . '/simhei.ttf'); //可填入备用字体

$requestPath = isset($_GET['path']) ? trim($_GET['path']) : '';
if ($requestPath === '') {
    outputErrorImage('Missing path parameter');
    exit;
}

$safePath = sanitizePath($requestPath);
$stats = updateAndGetStats($safePath);

if ($stats === null) {
    outputErrorImage('Access denied: path not allowed');
    exit;
}

generateStatsImage($stats, $safePath);

// ==================== 核心功能函数 ====================

function updateAndGetStats($path)
{
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return null; }

    $data = [];
    $fileSize = filesize(DATA_FILE);
    if ($fileSize > 0) {
        $content = fread($fp, $fileSize);
        if ($content !== false) $data = json_decode($content, true);
    }

    if (!is_array($data) || !isset($data['paths'])) {
        $data = ['lock' => false, 'paths' => []];
    }

    $today = date('Y-m-d');
    $pathExists = isset($data['paths'][$path]);

    if (!$pathExists) {
        if ($data['lock'] === true) {
            flock($fp, LOCK_UN); fclose($fp);
            return null;
        }
        // 新路径初始化，可选的 tag 和 display_name 默认为 null
        $data['paths'][$path] = [
            'total'        => 0,
            'today'        => 0,
            'last_date'    => $today,
            'tag'          => null,
            'display_name' => null
        ];
    }

    $stat = &$data['paths'][$path];
    $stat['total']++;

    if ($stat['last_date'] !== $today) {
        $stat['today'] = 1;
        $stat['last_date'] = $today;
    } else {
        $stat['today']++;
    }

    // 返回时带上自定义字段
    $result = [
        'total'        => $stat['total'],
        'today'        => $stat['today'],
        'last_date'    => $stat['last_date'],
        'tag'          => $stat['tag'] ?? null,
        'display_name' => $stat['display_name'] ?? null
    ];

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $result;
}

function sanitizePath($path)
{
    $path = str_replace(['..', './', '\\', '/', "\0"], '', $path);
    $path = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $path);
    if ($path === '') $path = 'default';
    return substr($path, 0, 64);
}

function generateStatsImage($stats, $path)
{
    $width = 500;
    $height = 200;
    $img = imagecreatetruecolor($width, $height);
    imageantialias($img, true);

    $bgWhite   = imagecolorallocate($img, 255, 255, 255);
    $borderGray = imagecolorallocate($img, 220, 220, 220);
    $textLight = imagecolorallocate($img, 150, 150, 150);
    $textDark  = imagecolorallocate($img, 50, 50, 50);
    $numberColor = imagecolorallocate($img, 33, 150, 243);

    imagefilledrectangle($img, 0, 0, $width, $height, $bgWhite);
    imagerectangle($img, 0, 0, $width-1, $height-1, $borderGray);

    $fontFile = file_exists(FONT_PATH) ? FONT_PATH : (file_exists(FONT_FALLBACK) ? FONT_FALLBACK : null);
    if (!$fontFile) {
        imagestring($img, 5, 10, 80, "Font not found. Please upload msyh.ttf/ttc", imagecolorallocate($img, 255, 0, 0));
        header('Content-Type: image/png');
        imagepng($img);
        imagedestroy($img);
        return;
    }

    // ========== 顶部显示文字：优先使用 display_name，否则显示路径 ==========
    $topText = !empty($stats['display_name']) ? $stats['display_name'] : '/' . $path;
    if (strlen($topText) > 40) {
        $topText = substr($topText, 0, 37) . '...';
    }
    $fontSizeSmall = 12;
    $textBox = imagettfbbox($fontSizeSmall, 0, $fontFile, $topText);
    $textWidth = $textBox[2] - $textBox[0];
    $textX = (int) round(($width - $textWidth) / 2);
    imagettftext($img, $fontSizeSmall, 0, $textX, 30, $textLight, $fontFile, $topText);
    imageline($img, 30, 45, $width - 30, 45, $borderGray);

    $colCenter = $width / 2;

    // 左侧总访问量
    $leftCenterX = $colCenter / 2;
    $labelLeft = '总访问量';
    $labelFontSize = 16;
    $labelBox = imagettfbbox($labelFontSize, 0, $fontFile, $labelLeft);
    $labelWidth = $labelBox[2] - $labelBox[0];
    $labelX = (int) round($leftCenterX - $labelWidth / 2);
    imagettftext($img, $labelFontSize, 0, $labelX, 90, $textDark, $fontFile, $labelLeft);

    $totalFormatted = number_format($stats['total']);
    $numberFontSize = 36;
    $numberBox = imagettfbbox($numberFontSize, 0, $fontFile, $totalFormatted);
    $numberWidth = $numberBox[2] - $numberBox[0];
    $numberX = (int) round($leftCenterX - $numberWidth / 2);
    imagettftext($img, $numberFontSize, 0, $numberX, 150, $numberColor, $fontFile, $totalFormatted);

    // 右侧今日访问
    $rightCenterX = $colCenter + $colCenter / 2;
    $labelRight = '今日访问';
    $labelBoxR = imagettfbbox($labelFontSize, 0, $fontFile, $labelRight);
    $labelWidthR = $labelBoxR[2] - $labelBoxR[0];
    $labelXR = (int) round($rightCenterX - $labelWidthR / 2);
    imagettftext($img, $labelFontSize, 0, $labelXR, 90, $textDark, $fontFile, $labelRight);

    $todayFormatted = number_format($stats['today']);
    $numberBoxR = imagettfbbox($numberFontSize, 0, $fontFile, $todayFormatted);
    $numberWidthR = $numberBoxR[2] - $numberBoxR[0];
    $numberXR = (int) round($rightCenterX - $numberWidthR / 2);
    imagettftext($img, $numberFontSize, 0, $numberXR, 150, $numberColor, $fontFile, $todayFormatted);

    // 底部更新时间
    $updateText = '更新于 ' . date('Y-m-d H:i:s');
    $updateFontSize = 10;
    $updateBox = imagettfbbox($updateFontSize, 0, $fontFile, $updateText);
    $updateWidth = $updateBox[2] - $updateBox[0];
    $updateX = (int) ($width - $updateWidth - 15);
    imagettftext($img, $updateFontSize, 0, $updateX, $height - 12, $textLight, $fontFile, $updateText);

    // 左下角绘制自定义 tag
    if (!empty($stats['tag'])) {
        $tagFile = __DIR__ . '/' . $stats['tag'];
        if (file_exists($tagFile) && is_readable($tagFile)) {
            $tag = imagecreatefrompng($tagFile);
            if ($tag) {
                $tag_w = imagesx($tag);
                $tag_h = imagesy($tag);
                $padding = 10;
                imagecopy($img, $tag, $padding, $height - $tag_h - $padding, 0, 0, $tag_w, $tag_h);
                imagedestroy($tag);
            }
        }
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    imagepng($img);
    imagedestroy($img);
}

function outputErrorImage($message)
{
    $width = 400;
    $height = 80;
    $img = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($img, 255, 240, 240);
    $textColor = imagecolorallocate($img, 255, 0, 0);
    imagefilledrectangle($img, 0, 0, $width, $height, $bgColor);

    $fontFile = file_exists(FONT_PATH) ? FONT_PATH : (file_exists(FONT_FALLBACK) ? FONT_FALLBACK : null);
    if ($fontFile) {
        imagettftext($img, 12, 0, 10, 35, $textColor, $fontFile, "Error: " . $message);
    } else {
        imagestring($img, 5, 10, 30, "Error: " . $message, $textColor);
    }

    header('Content-Type: image/png');
    imagepng($img);
    imagedestroy($img);
}
?>
