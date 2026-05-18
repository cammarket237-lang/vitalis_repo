<?php
// Only 2 photos per listing + 1 video if available (main_image + extra_image + video_360)
$host = file_exists('/.dockerenv') ? 'db' : 'localhost';
$pdo = new PDO("pgsql:host=$host;dbname=cammarket237_db", 'cammarket_user', 'CamMarket2024');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$stores = $pdo->query("SELECT id, user_id FROM cammarket237.stores LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
$s1=$stores[0]; $s2=isset($stores[1])?$stores[1]:$stores[0]; $s3=isset($stores[2])?$stores[2]:$stores[0];

$v1='https://samplelib.com/lib/preview/mp4/sample-5s.mp4';
$v2='https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4';
$v3='https://samplelib.com/lib/preview/mp4/sample-10s.mp4';

// Each item: photo1=main_image, photo2=extra_image, video=video_360 (optional)
$items=[
 ['title'=>'Ankara Dress - Ladies','cat'=>'Fashion','price'=>15000,'cond'=>'new','desc'=>'Beautiful ankara print dress. S/M/L/XL available.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1523381210434-271e8be1f52b?w=900','p2'=>'https://images.unsplash.com/photo-1572804013427-4d7ca7268217?w=900','vid'=>null],
 ['title'=>"Men's Classic Suit",'cat'=>'Fashion','price'=>45000,'cond'=>'new','desc'=>"Elegant 2-piece suit. Black, navy and grey.",'store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1594938298603-c8148c4b0e2e?w=900','p2'=>'https://images.unsplash.com/photo-1507679799987-c73779587ccf?w=900','vid'=>$v1],
 ['title'=>'Nike Sneakers Size 40-45','cat'=>'Fashion','price'=>22000,'cond'=>'new','desc'=>'Original Nike Air Max. All sizes available.','store'=>$s3,'town'=>'Bamenda','p1'=>'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=900','p2'=>'https://images.unsplash.com/photo-1549298916-b41d501d3772?w=900','vid'=>null],
 ['title'=>'Toyota Corolla 2010','cat'=>'Cars','price'=>3500000,'cond'=>'used','desc'=>'Automatic. Full AC. Very clean. Negotiable.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1549317661-bd32c8ce0db2?w=900','p2'=>'https://images.unsplash.com/photo-1590362891991-f776e747a588?w=900','vid'=>$v2],
 ['title'=>'Honda Accord 2014','cat'=>'Cars','price'=>4800000,'cond'=>'used','desc'=>'Very clean. Low mileage. One careful owner.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1606664515524-ed2f786a0bd6?w=900','p2'=>'https://images.unsplash.com/photo-1503376780353-7e6692767b70?w=900','vid'=>null],
 ['title'=>'Toyota Hilux Pickup 2018','cat'=>'Cars','price'=>9500000,'cond'=>'used','desc'=>'Double cabin. 4WD. Diesel. Excellent condition.','store'=>$s3,'town'=>'Bafoussam','p1'=>'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=900','p2'=>'https://images.unsplash.com/photo-1533473359331-0135ef1b58bf?w=900','vid'=>$v3],
 ['title'=>'L-Shape Sofa Set 6-Seater','cat'=>'Furniture','price'=>180000,'cond'=>'new','desc'=>'Modern L-shape sofa. Brown, grey, black available.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1555041469-a586c61ea9bc?w=900','p2'=>'https://images.unsplash.com/photo-1567538096630-e0c55bd6374c?w=900','vid'=>null],
 ['title'=>'King Size Wooden Bed Frame','cat'=>'Furniture','price'=>95000,'cond'=>'new','desc'=>'Solid mahogany with headboard. Easy assembly.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1505693314120-0d443867891c?w=900','p2'=>'https://images.unsplash.com/photo-1588046130717-0eb0c9a3ba15?w=900','vid'=>$v1],
 ['title'=>'Samsung 350L Double Door Fridge','cat'=>'Furniture','price'=>250000,'cond'=>'new','desc'=>'Energy saving. Silver finish. 1 year warranty.','store'=>$s3,'town'=>'Bamenda','p1'=>'https://images.unsplash.com/photo-1571175443880-49e1d25b2bc5?w=900','p2'=>'https://images.unsplash.com/photo-1584568694244-14fbdf83bd30?w=900','vid'=>null],
 ['title'=>'Premium Basmati Rice 25kg','cat'=>'Food','price'=>18000,'cond'=>'new','desc'=>'Premium long grain basmati rice. Wholesale available.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1536304929831-ee1ca9d44906?w=900','p2'=>'https://images.unsplash.com/photo-1516714435131-44d6b64dc6a2?w=900','vid'=>null],
 ['title'=>'Pure Red Palm Oil 20L','cat'=>'Food','price'=>12000,'cond'=>'new','desc'=>'100% pure. Farm fresh. Unrefined. 20 litre.','store'=>$s2,'town'=>'Bafoussam','p1'=>'https://images.unsplash.com/photo-1474979266404-7eaacbcd87c5?w=900','p2'=>'https://images.unsplash.com/photo-1598033129183-c4f50c736f10?w=900','vid'=>$v2],
 ['title'=>'Fresh Broiler Chickens 2-3kg','cat'=>'Food','price'=>4500,'cond'=>'new','desc'=>'Farm-raised. 2-3kg each. Bulk available.','store'=>$s3,'town'=>'Buea','p1'=>'https://images.unsplash.com/photo-1548550023-2bdb3c5beed7?w=900','p2'=>'https://images.unsplash.com/photo-1604503468506-a8da13d82791?w=900','vid'=>null],
 ['title'=>'Brazilian Hair Weave 18 inch','cat'=>'Health','price'=>35000,'cond'=>'new','desc'=>'100% Brazilian. Straight. Natural black.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1522337360788-8b13dee7a37e?w=900','p2'=>'https://images.unsplash.com/photo-1560869713-7d0a29430803?w=900','vid'=>null],
 ['title'=>'Complete Skincare Set 5 Pieces','cat'=>'Health','price'=>25000,'cond'=>'new','desc'=>'Cleanser, toner, serum, moisturizer, SPF50.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1596462502278-27bfdc403348?w=900','p2'=>'https://images.unsplash.com/photo-1556228578-8c89e6adf883?w=900','vid'=>$v3],
 ['title'=>'Adjustable Dumbbell Set 20kg','cat'=>'Health','price'=>45000,'cond'=>'new','desc'=>'2 x 10kg. Cast iron. Rubber grip. Home workouts.','store'=>$s3,'town'=>'Bamenda','p1'=>'https://images.unsplash.com/photo-1583454110551-21f2fa2afe61?w=900','p2'=>'https://images.unsplash.com/photo-1526506118085-60ce8714f8c5?w=900','vid'=>null],
 ['title'=>'Electrical Installation Service','cat'=>'Services','price'=>20000,'cond'=>'new','desc'=>'Professional wiring, sockets, lighting. Licensed.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1621905251189-08b45d6a269e?w=900','p2'=>'https://images.unsplash.com/photo-1504328345606-18bbc8c9d7d1?w=900','vid'=>null],
 ['title'=>'Catering Service - Events','cat'=>'Services','price'=>150000,'cond'=>'new','desc'=>'Weddings, parties, events. Min 50 people.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1555244162-803834f70033?w=900','p2'=>'https://images.unsplash.com/photo-1414235077428-338989a2e8c0?w=900','vid'=>$v1],
 ['title'=>'Professional House Cleaning','cat'=>'Services','price'=>15000,'cond'=>'new','desc'=>'Deep cleaning. Own supplies. Book anytime.','store'=>$s3,'town'=>'Buea','p1'=>'https://images.unsplash.com/photo-1581578731548-c64695cc6952?w=900','p2'=>'https://images.unsplash.com/photo-1527515637462-cff94eecc1ac?w=900','vid'=>null],
 ['title'=>'Airport Pickup Service 24/7','cat'=>'Transportation','price'=>10000,'cond'=>'new','desc'=>'Reliable pickup. AC vehicle. Douala & Yaounde.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1449965408869-eaa3f722e40d?w=900','p2'=>'https://images.unsplash.com/photo-1544620347-c4fd4a3d5957?w=900','vid'=>null],
 ['title'=>'Cargo Delivery Douala-Yaounde','cat'=>'Transportation','price'=>5000,'cond'=>'new','desc'=>'Daily cargo delivery. Door to door.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1601584115197-04ecc0da31d7?w=900','p2'=>'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?w=900','vid'=>$v2],
 ['title'=>'Professional Driver for Hire','cat'=>'Transportation','price'=>25000,'cond'=>'new','desc'=>'Full or half day. Very punctual.','store'=>$s3,'town'=>'Bamenda','p1'=>'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=900','p2'=>'https://images.unsplash.com/photo-1622473590773-f588134b6ce7?w=900','vid'=>null],
 ['title'=>'High Yield Maize Seeds 5kg','cat'=>'Agriculture','price'=>8000,'cond'=>'new','desc'=>'Certified. 5kg plants 1 acre. Disease resistant.','store'=>$s1,'town'=>'Bafoussam','p1'=>'https://images.unsplash.com/photo-1601833693920-a7f38e5a5ce5?w=900','p2'=>'https://images.unsplash.com/photo-1500382017468-9049fed747ef?w=900','vid'=>null],
 ['title'=>'NPK Fertilizer 50kg Bag','cat'=>'Agriculture','price'=>22000,'cond'=>'new','desc'=>'NPK 20-10-10. For maize and vegetables.','store'=>$s2,'town'=>'Bamenda','p1'=>'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=900','p2'=>'https://images.unsplash.com/photo-1464226184884-fa280b87c399?w=900','vid'=>$v3],
 ['title'=>'Honda Water Pump 2 inch','cat'=>'Agriculture','price'=>75000,'cond'=>'new','desc'=>'Honda engine. 2 inch. Farm irrigation.','store'=>$s3,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1592417817098-8fd3d9eb14a5?w=900','p2'=>'https://images.unsplash.com/photo-1467226632440-65f0b4957563?w=900','vid'=>null],
 ['title'=>'3-in-1 Baby Stroller','cat'=>'Kids','price'=>55000,'cond'=>'new','desc'=>'Car seat + carrycot + pushchair. 0-3 years.','store'=>$s1,'town'=>'Douala','p1'=>'https://images.unsplash.com/photo-1519689680058-324335c77eba?w=900','p2'=>'https://images.unsplash.com/photo-1515488042361-ee00e0ddd4e4?w=900','vid'=>null],
 ['title'=>'Kids School Uniform Set','cat'=>'Kids','price'=>8500,'cond'=>'new','desc'=>'Shirt + trousers/skirt + belt. Sizes 2-14.','store'=>$s2,'town'=>'Yaounde','p1'=>'https://images.unsplash.com/photo-1503454537195-1dcabb73ffb9?w=900','p2'=>'https://images.unsplash.com/photo-1518831959646-742c3a14ebf6?w=900','vid'=>$v1],
 ['title'=>'Educational Toys & Puzzles Set','cat'=>'Kids','price'=>12000,'cond'=>'new','desc'=>'Building blocks + puzzles + flash cards. Ages 2-6.','store'=>$s3,'town'=>'Buea','p1'=>'https://images.unsplash.com/photo-1566576912321-d58ddd7a6088?w=900','p2'=>'https://images.unsplash.com/photo-1551048632-24e444b48a3e?w=900','vid'=>null],
];

$stmtL=$pdo->prepare("INSERT INTO cammarket237.listings (store_id,user_id,title,description,price,original_price,category,condition,town,listing_type,status,moderation_status,stock_status,quantity_available,price_type) VALUES (?,?,?,?,?,?,?,?,?,'product','active','approved','in_stock',1,'fixed') RETURNING id");
$stmtM=$pdo->prepare("INSERT INTO cammarket237.listing_media (listing_id,media_type,media_url,sort_order,media_role) VALUES (?,?,?,?,?)");

$ok=0;
foreach($items as $item){
  try{
    $stmtL->execute([$item['store']['id'],$item['store']['user_id'],$item['title'],$item['desc'],$item['price'],$item['price'],$item['cat'],$item['cond'],$item['town']]);
    $lid=$stmtL->fetchColumn();
    $stmtM->execute([$lid,'image',$item['p1'],1,'main_image']);
    $stmtM->execute([$lid,'image',$item['p2'],2,'extra_image']);
    if($item['vid']) $stmtM->execute([$lid,'video',$item['vid'],3,'video_360']);
    $ok++;
    echo "OK [{$item['cat']}] {$item['title']}\n";
  }catch(Exception $e){echo "FAIL {$item['title']}: ".$e->getMessage()."\n";}
}

echo "\nInserted: $ok/".count($items)."\n\n";
$cats=$pdo->query("SELECT category,COUNT(*) cnt FROM cammarket237.listings WHERE status='active' GROUP BY category ORDER BY category")->fetchAll(PDO::FETCH_ASSOC);
foreach($cats as $c) echo "  {$c['category']}: {$c['cnt']}\n";
