<?php
$c = file_get_contents('/var/www/cammarket237/api.php');

$new_verify = <<<'VERIFY'
    // Fast AI verification
    $title = trim($_POST['title']);
    $category = trim($_POST['category']);
    $prompt = "Look at these 2 photos. Are they: 1) Real physical objects (not AI-generated, not internet photos, not screenshots)? 2) Both showing a '$title' ($category)? Answer ONLY with JSON: {\"ok\":true} or {\"ok\":false,\"reason\":\"short reason\"}";
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>json_encode([
            'model'=>'claude-haiku-4-5-20251001','max_tokens'=>60,
            'messages'=>[['role'=>'user','content'=>[
                ['type'=>'text','text'=>$prompt],
                ['type'=>'image','source'=>['type'=>'base64','media_type'=>mime_content_type($p1['path']),'data'=>base64_encode(file_get_contents($p1['path']))]],
                ['type'=>'image','source'=>['type'=>'base64','media_type'=>mime_content_type($p2['path']),'data'=>base64_encode(file_get_contents($p2['path']))]],
            ]]]
        ]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-api-key: '.CLAUDE_KEY,'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT=>20
    ]);
    $resp=curl_exec($ch); curl_close($ch);
    $ai=['approved'=>true,'reason'=>'Verified'];
    if($resp){$d=json_decode($resp,true);$t=preg_replace('/```json|```/','', $d['content'][0]['text']??'{}');$r=json_decode(trim($t),true);if(isset($r['ok'])){$ai=['approved'=>($r['ok']===true||$r['ok']==='true'),'reason'=>$r['reason']??''];}}
    if(!$ai['approved']){
VERIFY;

// Find and replace AI verification block
$patterns = [
    '// Fast parallel AI verification',
    '// Fast AI verification',
    '// AI verification',
    '// Run 2 checks in parallel'
];

$start = false;
foreach($patterns as $p){
    $pos = strpos($c, '    '.$p);
    if($pos !== false){ $start = $pos; break; }
}

$end = strpos($c, "    if(!\$ai['approved'])", $start);

if($start !== false && $end !== false){
    $c = substr($c, 0, $start) . $new_verify . substr($c, $end + strlen("    if(!\$ai['approved']){"));
    file_put_contents('/var/www/cammarket237/api.php', $c);
    echo "Done\n";
    echo "Uses haiku: ".(strpos($c,'claude-haiku')!==false?'YES':'NO')."\n";
    echo "Fast prompt: ".(strpos($c,'Real physical objects')!==false?'YES':'NO')."\n";
} else {
    echo "ERROR: block not found. start=$start end=$end\n";
    echo "Searching for: '// AI verification' -> ".strpos($c,'// AI verification')."\n";
}
