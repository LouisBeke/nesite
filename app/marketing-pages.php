<?php
declare(strict_types=1);

function marketing_e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function marketing_pages(): array
{
    $gameDefaults = [
        'kicker' => 'European game server hosting',
        'features' => ['European server location', 'DDoS-protected network', 'Automated deployment', 'Customer control panel'],
        'specs' => ['Control panel' => 'Included', 'Deployment' => 'Automatic after checkout', 'Network' => 'European, DDoS protected', 'Support' => 'Ticket, email, phone and Discord'],
    ];
    return [
        'minecraft' => array_replace($gameDefaults, [
            'path' => '/game-hosting/minecraft/', 'title' => 'Minecraft Server Hosting',
            'meta_title' => 'Minecraft Server Hosting Belgium & Europe | FoxNetwork',
            'description' => 'European Minecraft server hosting from Belgium with Paper, Fabric, Forge, NeoForge, Spigot, Sponge and Vanilla options. Plans from €5/month.',
            'lead' => 'Build a Minecraft server for friends, a modpack or a growing community on European infrastructure with the software choice made during checkout.',
            'price' => '€5.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=iron', 'cta' => 'Start a Minecraft server',
            'intro' => 'Choose the server software that matches your project instead of being locked into one generic image. FoxNetwork currently supports Vanilla, Paper, Spigot, Fabric, Forge, NeoForge and Sponge across its Minecraft plans.',
            'ideal' => ['Vanilla worlds with friends', 'Paper or Spigot plugin servers', 'Fabric, Forge and NeoForge modpacks', 'Communities that want an upgrade path'],
            'faqs' => [
                ['Can I use Paper, Forge or Fabric?', 'Yes. The available software options are shown during checkout and include Paper, Fabric, Forge, NeoForge, Spigot, Sponge and Vanilla.'],
                ['How much RAM should I choose?', 'A small Vanilla server can start on the entry plan. Modpacks, many plugins or more simultaneous players generally need a larger plan.'],
                ['Can I upgrade later?', 'Yes. Available upgrades are shown from the customer portal for eligible services.'],
            ],
            'related' => [['Rust hosting','/game-hosting/rust/'],['Palworld hosting','/game-hosting/palworld/'],['All plans','/store.php']],
        ]),
        'rust' => array_replace($gameDefaults, [
            'path' => '/game-hosting/rust/', 'title' => 'Rust Server Hosting',
            'meta_title' => 'Rust Server Hosting Belgium & Europe | FoxNetwork',
            'description' => 'Host a Rust dedicated server on European infrastructure with automated deployment, DDoS protection and a customer control panel. €15/month.',
            'lead' => 'Launch a persistent Rust world for your group or community with European connectivity and server controls in the FoxNetwork portal.',
            'price' => '€15.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=rust', 'cta' => 'Start a Rust server',
            'intro' => 'Rust servers benefit from stable resources, reliable storage and a network designed to remain reachable during busy wipes. FoxNetwork provides the dedicated-server software and customer controls needed to operate the service.',
            'ideal' => ['Private group servers', 'Community wipe cycles', 'European player bases', 'Owners who want direct support'],
            'faqs' => [['Is this a dedicated Rust server?', 'The product deploys the Rust dedicated-server software in an isolated managed service.'],['Where is it hosted?', 'The service runs on European infrastructure intended for low-latency regional play.'],['When is it created?', 'Provisioning starts automatically after checkout and payment confirmation.']],
            'related' => [['Minecraft hosting','/game-hosting/minecraft/'],['ARK hosting','/game-hosting/ark/'],['All plans','/store.php']],
        ]),
        'palworld' => array_replace($gameDefaults, [
            'path' => '/game-hosting/palworld/', 'title' => 'Palworld Server Hosting',
            'meta_title' => 'Palworld Server Hosting Europe | FoxNetwork Belgium',
            'description' => 'European Palworld dedicated server hosting with automated setup, customer controls and DDoS-protected connectivity. €15/month.',
            'lead' => 'Keep a shared Palworld world online for your group without leaving a personal computer running.',
            'price' => '€15.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=palworld', 'cta' => 'Start a Palworld server',
            'intro' => 'A hosted Palworld server gives your group a persistent world that is available independently of the original host. The FoxNetwork control center provides service status and management after deployment.',
            'ideal' => ['Persistent co-op worlds', 'European friend groups', 'Communities needing an always-on host', 'Players who prefer managed infrastructure'],
            'faqs' => [['Does the owner need to stay online?', 'No. The hosted dedicated server runs independently of a player computer.'],['Is setup automatic?', 'Yes. Provisioning begins automatically after a completed checkout.'],['Can I control the server?', 'Yes. The customer portal provides the controls enabled for the service.']],
            'related' => [['ARK hosting','/game-hosting/ark/'],['Astroneer hosting','/game-hosting/astroneer/'],['All plans','/store.php']],
        ]),
        'ark' => array_replace($gameDefaults, [
            'path' => '/game-hosting/ark/', 'title' => 'ARK Server Hosting',
            'meta_title' => 'ARK Server Hosting Europe | Survival Ascended & Evolved',
            'description' => 'European ARK server hosting for Survival Ascended and Survival Evolved with automated deployment and customer controls. €15/month.',
            'lead' => 'Run a persistent ARK world in Europe with a choice between Survival Ascended and Survival Evolved during checkout.',
            'price' => '€15.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=ark-survival-ascended', 'cta' => 'Start an ARK server',
            'intro' => 'ARK worlds need an always-available dedicated server and enough resources for the selected game version. FoxNetwork exposes both supported ARK server options at checkout and deploys the selected configuration.',
            'ideal' => ['ARK: Survival Ascended', 'ARK: Survival Evolved', 'Persistent tribe worlds', 'European communities'],
            'faqs' => [['Which ARK versions are supported?', 'Checkout currently offers ARK: Survival Ascended and ARK: Survival Evolved.'],['Is the world always available?', 'The dedicated service can stay online independently of individual players.'],['How do I get help?', 'Customers can open a ticket from the portal or contact FoxNetwork by email, phone or Discord.']],
            'related' => [['Palworld hosting','/game-hosting/palworld/'],['Rust hosting','/game-hosting/rust/'],['All plans','/store.php']],
        ]),
        'astroneer' => array_replace($gameDefaults, [
            'path' => '/game-hosting/astroneer/', 'title' => 'Astroneer Server Hosting',
            'meta_title' => 'Astroneer Dedicated Server Hosting Europe | FoxNetwork',
            'description' => 'European Astroneer dedicated server hosting with automated deployment, persistent worlds and a customer control panel. €13/month.',
            'lead' => 'Explore a persistent Astroneer world with friends on a dedicated server that does not depend on one player staying online.',
            'price' => '€13.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=austroneer', 'cta' => 'Start an Astroneer server',
            'intro' => 'The Astroneer dedicated-server software keeps a shared world available to your group. FoxNetwork handles the hosting layer and provides service management through the customer portal.',
            'ideal' => ['Persistent co-op saves', 'European groups', 'Always-on shared worlds', 'Players wanting direct support'],
            'faqs' => [['Is this the official dedicated-server software?', 'The product deploys the Astroneer Dedicated Server option configured in the FoxNetwork catalog.'],['Do I need to keep my PC online?', 'No. The hosted service runs separately from your gaming computer.'],['What happens after checkout?', 'The portal shows provisioning progress and the service appears in your account when ready.']],
            'related' => [['Palworld hosting','/game-hosting/palworld/'],['Minecraft hosting','/game-hosting/minecraft/'],['All plans','/store.php']],
        ]),
        'vps' => [
            'path' => '/vps-hosting/', 'kicker' => 'European cloud hosting', 'title' => 'Cloud VPS Hosting',
            'meta_title' => 'European Cloud VPS Hosting from Belgium | FoxNetwork',
            'description' => 'European cloud VPS hosting with root access, customer-selected operating system, live metrics, power controls and automated deployment. From €12/month.',
            'lead' => 'Get root access and a customer-managed cloud server with your preferred operating system, public IP addresses and live service controls.',
            'price' => '€12.00', 'suffix' => '/ month', 'checkout' => '/order.php?product=vps-1', 'cta' => 'Configure a cloud VPS',
            'features' => ['Customer-selected operating system', 'Root SSH access', 'IPv4 and IPv6', 'Metrics and power controls'],
            'intro' => 'FoxNetwork cloud VPS products combine automated provisioning with a customer control center. Choose the operating-system image at checkout, then manage power state, network information, storage, activity and supported settings from the portal.',
            'ideal' => ['Application and API hosting', 'Development environments', 'Discord bots needing more resources', 'Technical users needing root access'],
            'specs' => ['Entry plan' => '1 vCPU · 2 GB RAM · 50 GB storage', 'Operating system' => 'Customer choice at checkout', 'Access' => 'Root SSH credentials', 'Management' => 'Power, metrics, network, storage and activity'],
            'faqs' => [['Can I choose the operating system?', 'Yes. Current public images are shown as customer choices during checkout.'],['Do I receive root access?', 'Yes. The portal shows SSH access details for the deployed instance.'],['How quickly is it provisioned?', 'The system starts cloud provisioning immediately after an eligible checkout, with a retry queue as fallback.']],
            'related' => [['Free Discord hosting','/discord-bot-hosting/'],['Web hosting','/web-hosting/'],['All plans','/store.php']],
        ],
        'discord' => [
            'path' => '/discord-bot-hosting/', 'kicker' => 'FoxNetwork Free', 'title' => 'Free Discord Bot Hosting',
            'meta_title' => 'Free Discord Bot Hosting | Node.js & Python | FoxNetwork',
            'description' => 'Host your first Discord bot free with Node.js or Python support, no credit card and automated deployment on European infrastructure.',
            'lead' => 'Deploy a Node.js or Python Discord bot without a payment card, learn the platform and move to more resources when your project grows.',
            'price' => 'Free', 'suffix' => 'starter plan', 'checkout' => '/order.php?product=discord-bot-hosting', 'cta' => 'Host a bot free',
            'features' => ['No credit card', 'Node.js or Python', 'Automated deployment', 'Customer control panel'],
            'intro' => 'The free tier is a genuine starting point: create an account, choose Node.js or Python, provide the required configuration and deploy. It is designed for first bots and small projects rather than unlimited production workloads.',
            'ideal' => ['First Discord bots', 'Learning Node.js or Python', 'Small community utilities', 'Projects that may later need a VPS'],
            'specs' => ['Monthly price' => '€0.00', 'Software options' => 'Node.js and Python', 'Payment card' => 'Not required', 'Expiry' => 'No scheduled renewal charge for the free service'],
            'faqs' => [['Is it really free?', 'Yes. The current starter product is €0.00 and does not require a payment card. Resource limits still apply.'],['Which languages can I use?', 'Checkout currently offers Node.js and Python software options.'],['What if my bot grows?', 'You can move to a paid service or cloud VPS when you need more capacity and control.']],
            'related' => [['Cloud VPS','/vps-hosting/'],['Minecraft hosting','/game-hosting/minecraft/'],['All plans','/store.php']],
        ],
        'web' => [
            'path' => '/web-hosting/', 'kicker' => 'Simple European web hosting', 'title' => 'Web Hosting',
            'meta_title' => 'Web Hosting Belgium | FoxNetwork',
            'description' => 'Simple European web hosting from an independent Belgian provider, with transparent pricing and direct human support. Current starter plan is free.',
            'lead' => 'Launch a personal website or small web project with a current €0.00 starter product and direct access to FoxNetwork support.',
            'price' => 'Free', 'suffix' => 'current plan', 'checkout' => '/order.php?product=web', 'cta' => 'Start web hosting',
            'features' => ['European infrastructure', 'Transparent €0 starter price', 'Customer portal', 'Direct support'],
            'intro' => 'FoxNetwork web hosting is positioned as a straightforward entry point for a website or small project. The current catalog price is €0.00, matching the price shown across this page and the homepage.',
            'ideal' => ['Personal websites', 'Small web projects', 'Portfolio sites', 'Users wanting Belgian support'],
            'specs' => ['Current price' => '€0.00', 'Account' => 'FoxNetwork customer portal', 'Infrastructure' => 'European', 'Support' => 'Ticket, email, phone and Discord'],
            'faqs' => [['Is web hosting free?', 'The currently active web product is listed at €0.00. If the catalog price changes, checkout is the final source of truth before ordering.'],['Can I ask questions before ordering?', 'Yes. The contact page includes phone, email and a question form.'],['Is FoxNetwork a Belgian company?', 'Yes. FoxNetwork BV is based in Lembeek, Halle, Belgium.']],
            'related' => [['Cloud VPS','/vps-hosting/'],['Free Discord hosting','/discord-bot-hosting/'],['All plans','/store.php']],
        ],
    ];
}

function render_marketing_page(string $key): void
{
    $pages = marketing_pages();
    $page = $pages[$key] ?? null;
    if (!$page) { http_response_code(404); echo 'Page not found.'; return; }
    $faqs = $page['faqs'] ?? [];
    $schema = [
        '@context' => 'https://schema.org', '@type' => 'Service', 'name' => $page['title'],
        'description' => $page['description'], 'provider' => ['@type'=>'Organization','name'=>'FoxNetwork BV','url'=>'https://foxnetwork.be/'],
        'areaServed' => 'Europe', 'url' => 'https://foxnetwork.be'.$page['path'],
    ];
    ?>
<!doctype html>
<html lang="en-BE">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <title><?=marketing_e($page['meta_title'])?></title><meta name="description" content="<?=marketing_e($page['description'])?>">
 <link rel="canonical" href="https://foxnetwork.be<?=marketing_e($page['path'])?>"><meta name="theme-color" content="#080b10">
 <meta property="og:type" content="website"><meta property="og:title" content="<?=marketing_e($page['meta_title'])?>"><meta property="og:description" content="<?=marketing_e($page['description'])?>"><meta property="og:url" content="https://foxnetwork.be<?=marketing_e($page['path'])?>">
 <link rel="icon" href="/images/logo.png"><link rel="stylesheet" href="/css/fontawesome-all.min.css"><link rel="stylesheet" href="/assets/marketing.css?v=20260811a">
 <script type="application/ld+json"><?=json_encode($schema, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script>
 <?php if($faqs):?><script type="application/ld+json"><?=json_encode(['@context'=>'https://schema.org','@type'=>'FAQPage','mainEntity'=>array_map(fn($faq)=>['@type'=>'Question','name'=>$faq[0],'acceptedAnswer'=>['@type'=>'Answer','text'=>$faq[1]]],$faqs)],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?></script><?php endif?>
<?= opinly_head() ?>
</head>
<body class="marketing-body">
<nav class="mk-nav" data-nav><div class="mk-wrap mk-nav-inner"><a class="mk-brand" href="/"><img src="/images/logo.png" alt="FoxNetwork"><span>FOX<b>NETWORK</b></span></a><button class="mk-mobile-toggle" type="button" data-nav-toggle aria-label="Open navigation"><i class="fas fa-bars"></i></button><div class="mk-links"><a href="/#hosting">Hosting</a><a href="/game-hosting/minecraft/">Game servers</a><a href="/vps-hosting/">Cloud VPS</a><a href="/discord-bot-hosting/">Free Discord hosting</a><a href="/blog/">Blog</a><a href="/contact.html">Contact</a></div><div class="mk-actions"><a class="mk-button" href="/login.php">Sign in</a><a class="mk-button primary" href="/register.php">Create account</a></div></div></nav>
<main>
 <header class="mk-page-hero"><div class="mk-wrap mk-page-hero-grid"><div><span class="mk-kicker"><?=marketing_e($page['kicker'])?></span><h1><?=marketing_e($page['title'])?></h1><p><?=marketing_e($page['lead'])?></p><div class="mk-proofline"><?php foreach($page['features'] as $feature):?><span><i class="fas fa-check-circle"></i><?=marketing_e($feature)?></span><?php endforeach?></div></div><aside class="mk-page-card"><div class="mk-page-price"><small>STARTING AT</small><strong><?=marketing_e($page['price'])?></strong> <span><?=marketing_e($page['suffix'])?></span></div><div class="mk-checks"><?php foreach(array_slice($page['features'],0,4) as $feature):?><span><i class="fas fa-check"></i><?=marketing_e($feature)?></span><?php endforeach?></div><a class="mk-button primary" href="<?=marketing_e($page['checkout'])?>"><?=marketing_e($page['cta'])?> <i class="fas fa-arrow-right"></i></a></aside></div></header>
 <section class="mk-section alt"><div class="mk-wrap mk-content-grid"><article class="mk-prose"><span class="mk-kicker">Built for the actual workload</span><h2>Know what you are buying before checkout.</h2><p><?=marketing_e($page['intro'])?></p><h3>A good fit for</h3><ul><?php foreach($page['ideal'] as $item):?><li><?=marketing_e($item)?></li><?php endforeach?></ul></article><aside><div class="mk-spec-table"><?php foreach($page['specs'] as $label=>$value):?><div class="mk-spec-row"><span><?=marketing_e($label)?></span><b><?=marketing_e($value)?></b></div><?php endforeach?></div></aside></div></section>
 <section class="mk-section"><div class="mk-wrap"><div class="mk-section-head"><span class="mk-kicker">How it works</span><h2>From plan to running service.</h2></div><div class="mk-steps"><article class="mk-step"><span>STEP 01</span><h3>Choose the configuration</h3><p>Open the matching checkout and select the customer-facing options available for this product.</p></article><article class="mk-step"><span>STEP 02</span><h3>Confirm the order</h3><p>Review the price and configuration before the order and any required payment are created.</p></article><article class="mk-step"><span>STEP 03</span><h3>Manage it in your portal</h3><p>Follow provisioning and open the service controls from your FoxNetwork account.</p></article></div></div></section>
 <section class="mk-section alt"><div class="mk-wrap"><div class="mk-section-head"><span class="mk-kicker">Product FAQ</span><h2>Questions about <?=marketing_e($page['title'])?>.</h2></div><div class="mk-faq"><?php foreach($faqs as $faq):?><details><summary><?=marketing_e($faq[0])?></summary><p><?=marketing_e($faq[1])?></p></details><?php endforeach?></div></div></section>
 <section class="mk-section"><div class="mk-wrap"><div class="mk-section-head"><span class="mk-kicker">Explore next</span><h2>Related FoxNetwork services.</h2></div><div class="mk-related"><?php foreach($page['related'] as $related):?><a href="<?=marketing_e($related[1])?>"><?=marketing_e($related[0])?><small>View product details →</small></a><?php endforeach?></div></div></section>
 <section class="mk-cta"><div class="mk-wrap"><div class="mk-cta-box"><div><h2>Ready to get started?</h2><p>Choose the configured product, or ask FoxNetwork a question first.</p></div><div class="mk-actions"><a class="mk-button" href="/contact.html">Ask a question</a><a class="mk-button primary" href="<?=marketing_e($page['checkout'])?>"><?=marketing_e($page['cta'])?></a></div></div></div></section>
</main>
<footer class="mk-footer"><div class="mk-wrap"><div class="mk-footer-grid"><div><a class="mk-brand" href="/"><img src="/images/logo.png" alt=""><span>FOX<b>NETWORK</b></span></a><p>Independent Belgian hosting on European infrastructure, with automated deployment and direct support.</p></div><div><h3>Game hosting</h3><a href="/game-hosting/minecraft/">Minecraft</a><a href="/game-hosting/rust/">Rust</a><a href="/game-hosting/palworld/">Palworld</a><a href="/game-hosting/ark/">ARK</a><a href="/game-hosting/astroneer/">Astroneer</a></div><div><h3>Cloud & web</h3><a href="/vps-hosting/">Cloud VPS</a><a href="/discord-bot-hosting/">Discord bots</a><a href="/web-hosting/">Web hosting</a></div><div><h3>Company</h3><a href="/blog/">Blog</a><a href="/contact.html">Contact</a><a href="/privacy.html">Privacy</a><a href="/login.php">Client portal</a></div></div><div class="mk-footer-bottom"><span>© 2020–2026 FoxNetwork BV.</span><span>info@foxnetwork.be · +32 (0)2 615 76 80</span></div></div></footer>
<script defer src="https://cdn.jsdelivr.net/npm/animejs@4.5.0/dist/bundles/anime.umd.min.js" integrity="sha384-InMmvD3VoYcY7hGjSC80aLb2bNNE4CzpX+Eq6FVDlmB0IKgDvmfPw4UY8L/M++iG" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script defer src="/js/marketing-animations.js?v=20260829b"></script>
<script>document.querySelector('[data-nav-toggle]')?.addEventListener('click',()=>document.querySelector('[data-nav]')?.classList.toggle('is-open'));</script>
</body></html>
<?php
}

render_marketing_page((string)($marketingPage ?? ''));
