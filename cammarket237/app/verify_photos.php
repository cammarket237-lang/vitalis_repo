<?php
// ═══════════════════════════════════════════════════════════
// CamMarket237 - Local Photo Verification
// Zero API cost - uses PHP GD + EXIF only
// Detects: AI generated, screenshots, blank, identical, fake
// ═══════════════════════════════════════════════════════════

function verifyItemPhotos($photos) {
    $errors = [];
    $stats  = [];

    foreach ($photos as $idx => $path) {
        $label = 'Photo ' . ($idx + 1);

        // ── CHECK 1: File exists ───────────────────────────
        if (!file_exists($path)) {
            $errors[] = "$label: File not found";
            continue;
        }

        // ── CHECK 2: Valid image ───────────────────────────
        $info = @getimagesize($path);
        if (!$info) {
            $errors[] = "$label: Not a valid image file. Please upload JPG, PNG or WEBP";
            continue;
        }

        $w = $info[0]; $h = $info[1]; $type = $info[2];

        // ── CHECK 3: Minimum size ──────────────────────────
        if ($w < 200 || $h < 200) {
            $errors[] = "$label: Too small ({$w}x{$h}px). Please upload a clear photo (min 200x200px)";
            continue;
        }

        // ── CHECK 4: File size (blank/corrupted) ───────────
        $filesize = filesize($path);
        if ($filesize < 8000) {
            $errors[] = "$label: File too small ($filesize bytes). Photo may be blank or corrupted";
            continue;
        }

        // ── CHECK 5: EXIF Analysis (camera metadata) ───────
        $exifScore = 0; // higher = more likely real camera photo
        $exifData  = [];
        if ($type == IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path, 'ANY_TAG', true);
            if ($exif) {
                // Real cameras always write these EXIF fields
                if (!empty($exif['IFD0']['Make']))        { $exifScore += 3; $exifData['camera'] = $exif['IFD0']['Make']; }
                if (!empty($exif['IFD0']['Model']))       { $exifScore += 3; $exifData['model']  = $exif['IFD0']['Model']; }
                if (!empty($exif['EXIF']['DateTimeOriginal'])) { $exifScore += 2; }
                if (!empty($exif['EXIF']['FocalLength']))  $exifScore += 2;
                if (!empty($exif['EXIF']['ISOSpeedRatings'])) $exifScore += 2;
                if (!empty($exif['EXIF']['ExposureTime'])) $exifScore += 2;
                if (!empty($exif['EXIF']['Flash']))        $exifScore += 1;

                // Software field - AI tools often write their name here
                $software = strtolower($exif['IFD0']['Software'] ?? '');
                $aiSoftware = ['midjourney','stable diffusion','dall-e','dall.e',
                               'firefly','canva ai','bing image','deepai',
                               'artbreeder','nightcafe','runway','leonardo'];
                foreach ($aiSoftware as $ai) {
                    if (strpos($software, $ai) !== false) {
                        $errors[] = "$label: Image appears to be AI-generated (software: $software). Only real photos are allowed";
                        break;
                    }
                }

                // Screenshot indicators
                $screenshotSW = ['screenshot','snagit','lightshot','snipping','grab',
                                 'snip & sketch','screenpresso'];
                foreach ($screenshotSW as $sw) {
                    if (strpos($software, $sw) !== false) {
                        $errors[] = "$label: Screenshot detected. Please take a real photo of the item";
                        break;
                    }
                }
            }
        }

        // Load image for pixel analysis
        $img = null;
        if ($type == IMAGETYPE_JPEG)      $img = @imagecreatefromjpeg($path);
        elseif ($type == IMAGETYPE_PNG)   $img = @imagecreatefrompng($path);
        elseif ($type == IMAGETYPE_WEBP)  $img = @imagecreatefromwebp($path);

        if (!$img) {
            $errors[] = "$label: Could not process image";
            continue;
        }

        // ── Sample at 64x64 for detailed analysis ──────────
        $size   = 64;
        $small  = imagecreatetruecolor($size, $size);
        imagecopyresampled($small, $img, 0, 0, 0, 0, $size, $size, $w, $h);
        imagedestroy($img);

        $pixels = $size * $size;
        $rVals = []; $gVals = []; $bVals = [];
        $totalR=$totalG=$totalB = 0;
        $uniqueColors = [];
        $edgeCount = 0;

        for ($x=0; $x<$size; $x++) {
            for ($y=0; $y<$size; $y++) {
                $rgb = imagecolorat($small, $x, $y);
                $r=($rgb>>16)&0xFF; $g=($rgb>>8)&0xFF; $b=$rgb&0xFF;
                $totalR+=$r; $totalG+=$g; $totalB+=$b;
                $rVals[]=$r; $gVals[]=$g; $bVals[]=$b;
                $bucket = (int)($r/16).','. (int)($g/16).','. (int)($b/16);
                $uniqueColors[$bucket] = true;
            }
        }

        $avgR=$totalR/$pixels; $avgG=$totalG/$pixels; $avgB=$totalB/$pixels;
        $avgBright=($avgR+$avgG+$avgB)/3;
        $uniqueCount=count($uniqueColors);

        // ── CHECK 6: Solid/blank color ─────────────────────
        if ($uniqueCount < 15) {
            $errors[] = "$label: Image appears blank or is a solid color ($uniqueCount colors found). Please take a real photo";
        }

        // ── CHECK 7: Too dark ──────────────────────────────
        if ($avgBright < 20) {
            $errors[] = "$label: Photo is too dark (brightness: ".round($avgBright)."/255). Use better lighting";
        }

        // ── CHECK 8: Overexposed ───────────────────────────
        if ($avgBright > 240) {
            $errors[] = "$label: Photo is overexposed/blank white. Please retake in normal lighting";
        }

        // ── CHECK 9: Variance/contrast ────────────────────
        $variance=0;
        foreach ($rVals as $i => $r) {
            $bright=($r + $gVals[$i] + $bVals[$i])/3;
            $variance+=pow($bright-$avgBright, 2);
        }
        $stdDev=sqrt($variance/$pixels);

        if ($stdDev < 8) {
            $errors[] = "$label: Image lacks detail (flat/blurry). Make sure item is in focus with good lighting";
        }

        // ── CHECK 10: AI-GENERATED DETECTION ──────────────
        // AI images have specific statistical signatures:
        // 1. Suspiciously uniform noise (too smooth)
        // 2. Perfect color distribution (not natural)
        // 3. PNG format with no EXIF (common for AI outputs)
        // 4. Very large PNG files (AI tools export high-res PNGs)
        // 5. Unrealistically perfect channel balance

        $aiIndicators = 0;
        $aiReasons = [];

        // Indicator A: PNG with no camera EXIF + large file = likely AI/generated
        if ($type == IMAGETYPE_PNG && $filesize > 500000 && $exifScore === 0) {
            $aiIndicators++;
            $aiReasons[] = 'Large PNG with no camera data';
        }

        // Indicator B: Color channels too perfectly balanced (AI tendency)
        $channelDiff = abs($avgR - $avgG) + abs($avgG - $avgB) + abs($avgR - $avgB);
        if ($channelDiff < 3 && $uniqueCount > 100) {
            $aiIndicators++;
            $aiReasons[] = 'Unnaturally balanced colors';
        }

        // Indicator C: Too-perfect standard deviation (AI images are too clean)
        // Real photos have grain/noise; stdDev between 30-80 is natural
        if ($stdDev > 15 && $stdDev < 25 && $uniqueCount > 200) {
            $aiIndicators++;
            $aiReasons[] = 'Suspiciously uniform texture';
        }

        // Indicator D: Detect gradient/render patterns
        // Sample diagonal - natural photos have irregular patterns
        $diagDiffs = [];
        for ($d=1; $d<$size-1; $d++) {
            $rgb1 = imagecolorat($small, $d, $d);
            $rgb2 = imagecolorat($small, $d+1, $d+1);
            $b1=($rgb1>>16)&0xFF; $b2=($rgb2>>16)&0xFF;
            $diagDiffs[] = abs($b1-$b2);
        }
        $avgDiagDiff = array_sum($diagDiffs)/count($diagDiffs);
        $diagVariance = 0;
        foreach ($diagDiffs as $d) $diagVariance += pow($d - $avgDiagDiff, 2);
        $diagStdDev = sqrt($diagVariance/count($diagDiffs));

        // AI images have very uniform pixel transitions along diagonals
        if ($diagStdDev < 2 && $avgDiagDiff < 5 && $uniqueCount > 50) {
            $aiIndicators++;
            $aiReasons[] = 'Unnatural uniform pixel transitions';
        }

        // Indicator E: Screenshot detection
        // Screenshots often have very high unique colors but perfect edges
        // and tend to have extremely consistent saturation patterns
        $satTotal = 0;
        for ($x=0;$x<$size;$x++) for ($y=0;$y<$size;$y++) {
            $rgb=imagecolorat($small,$x,$y);
            $r=($rgb>>16)&0xFF; $g=($rgb>>8)&0xFF; $b2=$rgb&0xFF;
            $maxC=max($r,$g,$b2); $minC=min($r,$g,$b2);
            $satTotal+=($maxC>0)?($maxC-$minC)/$maxC:0;
        }
        $avgSat = $satTotal/$pixels;

        // Near-zero saturation across whole image = likely screenshot of white page
        if ($avgSat < 0.05 && $avgBright > 200 && $uniqueCount < 100) {
            $errors[] = "$label: Image looks like a screenshot of a white page. Please take a real photo";
        }

        // ── COMBINE AI INDICATORS ──────────────────────────
        if ($aiIndicators >= 3) {
            $errors[] = "$label: Image may be AI-generated or computer-rendered (".implode(', ',$aiReasons)."). Only real photos are allowed";
        }

        // Save stats for cross-photo comparison
        $stats[$idx] = [
            'label'    => $label,
            'w'=>$w, 'h'=>$h, 'filesize'=>$filesize,
            'avgR'=>$avgR, 'avgG'=>$avgG, 'avgB'=>$avgB,
            'bright'   => $avgBright,
            'stdDev'   => $stdDev,
            'unique'   => $uniqueCount,
            'exifScore'=> $exifScore,
            'small'    => $small,
        ];
    }

    // ── CROSS-PHOTO CHECKS ─────────────────────────────────
    if (count($stats) >= 2) {
        $keys = array_keys($stats);
        for ($i=0;$i<count($keys)-1;$i++) {
            for ($j=$i+1;$j<count($keys);$j++) {
                $a=$stats[$keys[$i]]; $b=$stats[$keys[$j]];

                // CHECK 11: Not identical copies
                $diffSum=0;
                for ($x=0;$x<64;$x++) for ($y=0;$y<64;$y++) {
                    $rgbA=imagecolorat($a['small'],$x,$y);
                    $rgbB=imagecolorat($b['small'],$x,$y);
                    $rA=($rgbA>>16)&0xFF; $gA=($rgbA>>8)&0xFF; $bA=$rgbA&0xFF;
                    $rB=($rgbB>>16)&0xFF; $gB=($rgbB>>8)&0xFF; $bB=$rgbB&0xFF;
                    $diffSum+=abs($rA-$rB)+abs($gA-$gB)+abs($bA-$bB);
                }
                $avgDiff=$diffSum/(64*64*3);
                if ($avgDiff < 5)
                    $errors[] = $a['label'].' and '.$b['label'].': Photos are identical. Please upload different angles of the same item';

                // CHECK 12: Photos show same item (overall + regional color match)
                $colorDiff = abs($a['avgR']-$b['avgR'])
                           + abs($a['avgG']-$b['avgG'])
                           + abs($a['avgB']-$b['avgB']);
                if ($colorDiff > 150) {
                    $errors[] = $a['label'].' and '.$b['label'].': Photos appear to show different items (different colors). All photos must be different angles of the same item';
                } else {
                    // Regional check: compare 4 quadrants — catches same-average-color but structurally different items
                    // (e.g. two different dark-colored phones still differ region-by-region)
                    $regionMismatches = 0;
                    $quadrants = [[0,0,32,32],[32,0,64,32],[0,32,32,64],[32,32,64,64]];
                    foreach ($quadrants as $q) {
                        list($x1,$y1,$x2,$y2) = $q;
                        $rA=$gA=$bA=$rB=$gB=$bB=0; $cnt=0;
                        for ($qx=$x1;$qx<$x2;$qx++) for ($qy=$y1;$qy<$y2;$qy++) {
                            $cA=imagecolorat($a['small'],$qx,$qy); $cB=imagecolorat($b['small'],$qx,$qy);
                            $rA+=($cA>>16)&0xFF; $gA+=($cA>>8)&0xFF; $bA+=$cA&0xFF;
                            $rB+=($cB>>16)&0xFF; $gB+=($cB>>8)&0xFF; $bB+=$cB&0xFF;
                            $cnt++;
                        }
                        $rDiff = abs($rA/$cnt-$rB/$cnt)+abs($gA/$cnt-$gB/$cnt)+abs($bA/$cnt-$bB/$cnt);
                        if ($rDiff > 100) $regionMismatches++;
                    }
                    if ($regionMismatches >= 3)
                        $errors[] = $a['label'].' and '.$b['label'].': Photos appear to show different items (different structure). All photos must be different angles of the same item';
                }
            }
        }
    }

    foreach ($stats as $s) if(isset($s['small'])) imagedestroy($s['small']);

    return ['approved'=>empty($errors), 'errors'=>$errors];
}

// ── MAIN ENTRY POINT ───────────────────────────────────────
function runPhotoVerification($p1, $p2, $p3=null) {
    $photos = array_filter([$p1, $p2, $p3], function($p){ return $p && file_exists($p); });
    $r = verifyItemPhotos(array_values($photos));
    if ($r['approved']) return ['approved'=>true, 'reason'=>'All photos verified successfully'];
    return [
        'approved'   => false,
        'reason'     => $r['errors'][0],
        'all_errors' => $r['errors'],
    ];
}

// ═══════════════════════════════════════════════════════════
// CamMarket237 - Content Moderation
// Checks item title/description for dangerous/banned content
// Flags to admin if dangerous item detected
// ═══════════════════════════════════════════════════════════

// ── BANNED CATEGORIES ──────────────────────────────────────
$BANNED_KEYWORDS = [

    // Weapons
    'weapons' => [
        'gun','pistol','rifle','shotgun','revolver','firearm','weapon',
        'ak47','ak-47','ak 47','m16','glock','beretta','uzi',
        'bullet','ammunition','ammo','cartridge','magazine clip',
        'silencer','suppressor','grenade','explosive','bomb',
        'landmine','rpg','rocket launcher','bazooka',
        'knife fight','combat knife','switchblade','brass knuckle',
        'taser','stun gun','pepper spray'
    ],

    // Drugs & Narcotics
    'drugs' => [
        'cocaine','heroin','methamphetamine','meth','crack cocaine',
        'ecstasy','mdma','lsd','acid tabs','mushroom drugs',
        'cannabis','marijuana','weed','ganja','bhang','tramadol abuse',
        'codeine syrup','lean','purple drank','opioid',
        'fentanyl','ketamine','rohypnol','date rape drug',
        'drug dealer','narcotics','illicit drug','drug trafficking'
    ],

    // Dangerous Chemicals
    'chemicals' => [
        'cyanide','arsenic','chlorine gas','sarin','nerve agent',
        'mustard gas','poison','venom','toxic chemical',
        'sulfuric acid attack','hydrochloric acid weapon',
        'fertilizer bomb','ammonium nitrate bomb',
        'homemade explosive','pipe bomb','ied'
    ],

    // Human Trafficking / Exploitation
    'trafficking' => [
        'human trafficking','slave','slavery','child labor',
        'sex trafficking','prostitution','escort service',
        'buy human','sell human','organ trafficking',
        'fake passport','counterfeit document','illegal visa'
    ],

    // Counterfeit / Fraud
    'counterfeit' => [
        'fake currency','counterfeit money','fake dollar','fake cfa',
        'fake banknote','counterfeit product','pirated software',
        'stolen goods','stolen phone','stolen car',
        'credit card dump','stolen credit card','carding'
    ],

    // Wildlife / Poaching
    'poaching' => [
        'ivory','elephant tusk','rhino horn','bushmeat illegal',
        'protected species','pangolin','gorilla parts',
        'lion bone','tiger parts','endangered animal'
    ],
];

// ── SUSPICIOUS KEYWORDS (warn but don't auto-reject) ───────
$SUSPICIOUS_KEYWORDS = [
    'second hand gun','used firearm','legal gun','licensed weapon',
    'security guard weapon','police equipment','military surplus',
    'pharmaceutical','prescription drug','controlled substance',
    'chemicals for sale','industrial chemical',
];

function moderateContent($title, $description, $category, $storeId, $userId) {
    global $BANNED_KEYWORDS, $SUSPICIOUS_KEYWORDS;

    $text = strtolower($title . ' ' . $description . ' ' . $category);

    $flagged      = [];
    $flagCategory = '';
    $severity     = 'low'; // low, medium, high, critical

    // Check banned keywords
    foreach ($BANNED_KEYWORDS as $catName => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($text, $kw) !== false) {
                $flagged[]    = $kw;
                $flagCategory = $catName;
                $severity     = in_array($catName, ['weapons','drugs','chemicals','trafficking'])
                    ? 'critical' : 'high';
            }
        }
    }

    // Check suspicious keywords
    $suspicious = [];
    foreach ($SUSPICIOUS_KEYWORDS as $kw) {
        if (strpos($text, $kw) !== false) $suspicious[] = $kw;
    }

    $result = [
        'allowed'      => empty($flagged),
        'flagged'      => $flagged,
        'suspicious'   => $suspicious,
        'category'     => $flagCategory,
        'severity'     => $severity,
        'reason'       => '',
    ];

    if (!empty($flagged)) {
        $categoryMessages = [
            'weapons'      => 'Weapons and firearms are not allowed on CamMarket237',
            'drugs'        => 'Drugs and narcotics are strictly prohibited',
            'chemicals'    => 'Dangerous chemicals and explosives are not allowed',
            'trafficking'  => 'This content violates human rights laws',
            'counterfeit'  => 'Counterfeit and stolen goods are not allowed',
            'poaching'     => 'Protected wildlife and poached goods are illegal',
        ];
        $result['reason'] = $categoryMessages[$flagCategory]
            ?? 'This item violates CamMarket237 community guidelines';

        // Auto-flag to admin
        flagToAdmin($title, $description, $category, $storeId, $userId, $flagged, $flagCategory, $severity);
    }

    return $result;
}

function flagToAdmin($title, $desc, $cat, $storeId, $userId, $keywords, $flagCategory, $severity) {
    try {
        // Log to DB
        $pdo = new PDO(DB_DSN, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Check if verification_queue table exists
        $check = $pdo->query("SELECT to_regclass('cammarket237.verification_queue')")->fetchColumn();
        if ($check) {
            $stmt = $pdo->prepare("
                INSERT INTO cammarket237.verification_queue
                (user_id, store_id, content_type, content_title, content_description,
                 flag_reason, flag_keywords, flag_category, severity, status, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,'pending',NOW())
            ");
            $stmt->execute([
                $userId,
                $storeId,
                'listing',
                $title,
                $desc,
                'Dangerous/banned content detected: ' . implode(', ', $keywords),
                implode(',', $keywords),
                $flagCategory,
                $severity,
            ]);
        }

        // Also write to log file as backup
        $logEntry = date('Y-m-d H:i:s') . " | SEVERITY: $severity | CATEGORY: $flagCategory"
            . " | USER: $userId | STORE: $storeId | TITLE: $title"
            . " | KEYWORDS: " . implode(', ', $keywords) . "\n";
        file_put_contents(
            '/var/www/cammarket237/logs/flagged_content.log',
            $logEntry,
            FILE_APPEND | LOCK_EX
        );

    } catch (Exception $e) {
        // Fail silently - don't block the rejection message
        error_log('CamMarket237 flag error: ' . $e->getMessage());
    }
}

// ── FULL VERIFICATION (photos + content) ───────────────────
function runFullVerification($p1, $p2, $p3, $title, $description, $category, $storeId, $userId) {

    // Step 1: Photo verification
    $photos = array_filter([$p1, $p2, $p3], function($p){ return $p && file_exists($p); });
    $photoResult = verifyItemPhotos(array_values($photos));

    if (!$photoResult['approved']) {
        return [
            'approved' => false,
            'rejected' => true,
            'reason'   => $photoResult['errors'][0],
            'all_errors' => $photoResult['errors'],
            'type'     => 'photo',
        ];
    }

    // Step 2: Content moderation
    $contentResult = moderateContent($title, $description, $category, $storeId, $userId);

    if (!$contentResult['allowed']) {
        return [
            'approved' => false,
            'rejected' => true,
            'reason'   => $contentResult['reason'],
            'flagged'  => true,
            'severity' => $contentResult['severity'],
            'type'     => 'content',
            'message'  => 'Your listing has been flagged and sent to admin for review. If this is a mistake please contact support.',
        ];
    }

    // All checks passed
    return [
        'approved' => true,
        'reason'   => 'Verified successfully',
        'suspicious' => $contentResult['suspicious'],
    ];
}
