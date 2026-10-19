<?php
// Public discovery prototype: one existing exhibition plus illustrative future concepts.
$ideas = [
 ['title'=>'The Birth of the Internet','topic'=>'Technology & History','detail'=>'How a research experiment became a network that changed the world.','era'=>'05 CHAPTERS · AVAILABLE NOW','art'=>'network','live'=>true],
 ['title'=>'Cities After Dark','topic'=>'Places & Culture','detail'=>'A visual journey through the worlds that awaken after sundown.','era'=>'EXHIBITION CONCEPT','art'=>'city','live'=>false],
 ['title'=>'The Sound of 2016','topic'=>'Music & Culture','detail'=>'A year of unforgettable melodies, electronic music and cultural moments.','era'=>'EXHIBITION CONCEPT','art'=>'sound','live'=>false],
 ['title'=>'Beyond the Horizon','topic'=>'Science & Discovery','detail'=>'Questions, discoveries and images that changed how we see space.','era'=>'EXHIBITION CONCEPT','art'=>'space','live'=>false],
];
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="Explore the first digital exhibitions on Exhibit, and imagine what's coming next.">
<title>Explore Exhibitions — Exhibit (Preview)</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
<link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/explore.css">
</head><body>
<header class="site-header"><div class="shell header-inner">
<a class="wordmark" href="index.php" aria-label="Exhibit home"><span class="mark" aria-hidden="true">E<span>✳</span></span> exhibit<span class="wordmark-period">.</span></a>
<nav aria-label="Main navigation"><a href="index.php">Home</a><a href="explore.php" aria-current="page">Explore</a><a href="my-exhibitions.php">My Exhibitions</a><a href="sign-in.php">Sign in</a></nav>
<span class="edition">CURATED DISCOVERY</span>
</div></header>
<main>
<section class="explore-hero shell" aria-labelledby="explore-title">
  <div><p class="eyebrow"><span class="eyebrow-line"></span> EXHIBIT / DISCOVER</p><h1 id="explore-title">A world of <em>curiosity.</em></h1><p>Stories to wander through. Ideas to stay with. Find a subject that makes you want to know more, and step inside.</p></div>
  <div class="explore-orbit" aria-hidden="true"><div class="orbit-ring outer"></div><div class="orbit-ring inner"></div><span class="orbit-star">✳</span><span class="orbit-caption">WHAT WILL YOU DISCOVER?</span></div>
</section>
<section class="explore-section shell" aria-labelledby="explore-list-title"><div class="explore-heading"><div><p class="eyebrow">THE EXHIBITIONS / 001</p><h2 id="explore-list-title">Start exploring<span class="heading-dot">.</span></h2></div><p>One exhibition to explore today.<br>More possibilities ahead.</p></div>
<div class="explore-cards">
<?php foreach($ideas as $i=>$item): ?>
<article class="explore-card">
<div class="explore-art explore-art--<?=esc($item['art'])?>" aria-hidden="true">
<?php if($item['art']==='network'): ?><div class="internet-art"><span class="internet-prompt">&gt; CONNECTING WORLDS_</span><strong>www.</strong><div class="network-points"><i></i><i></i><i></i><i></i></div></div>
<?php elseif($item['art']==='city'): ?><div class="city-moon"></div><div class="city-line"></div><div class="skyline"><i></i><i></i><i></i><i></i><i></i><i></i></div>
<?php elseif($item['art']==='sound'): ?><div class="vinyl"><span></span></div><div class="sound-waves"><i></i><i></i><i></i><i></i><i></i></div>
<?php else: ?><div class="space-ring"><span></span></div><div class="space-dot"></div><?php endif; ?>
<span class="art-index">EXHIBIT / <?=str_pad((string)($i+1),3,'0',STR_PAD_LEFT)?></span>
</div>
<div class="explore-card-body"><div class="explore-meta"><span><?=esc(strtoupper($item['topic']))?></span><span class="explore-status <?= $item['live']?'is-live':'is-concept' ?>"><?= $item['live']?'● PUBLISHED':'CONCEPT PREVIEW' ?></span></div><h3><?=esc($item['title'])?></h3><p><?=esc($item['detail'])?></p><div class="explore-bottom"><span><?=esc($item['era'])?></span><?php if($item['live']): ?><a href="exhibition.php">View exhibition <span aria-hidden="true">↗</span></a><?php else: ?><span class="concept-note">Not yet available</span><?php endif;?></div></div>
</article>
<?php endforeach; ?>
</div>
<p class="explore-disclaimer" role="note">✳ This is an early discovery preview. Only <strong>The Birth of the Internet</strong> is a complete exhibition; the other cards are illustrative ideas, not published works.</p>
</section>
<section class="explore-end shell"><span class="explore-end-star" aria-hidden="true">✳</span><div><p class="eyebrow">CREATE SOMETHING WONDERFUL</p><h2>Have a story worth <em>sharing?</em></h2><p>Exhibit is becoming a home for people who want to turn their curiosity into guided digital experiences.</p></div><a class="primary-link" href="my-exhibitions.php">Explore the Studio preview <span aria-hidden="true">↗</span></a></section>
</main><footer class="footer"><div class="shell footer-inner"><span class="footer-brand">exhibit<span>.</span></span><span>Made for the endlessly curious.</span><span>© <?=date('Y')?> Exhibit</span></div></footer>
</body></html>
