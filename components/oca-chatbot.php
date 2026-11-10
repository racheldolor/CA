<?php
$chatbotBasePath = $chatbotBasePath ?? '';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($chatbotBasePath) ?>components/oca-chatbot.css">
<div class="oca-chatbot" data-chatbot data-endpoint="<?= htmlspecialchars($chatbotBasePath) ?>api/oca-chat.php">
    <button class="oca-chatbot-launcher" type="button" data-chatbot-toggle aria-label="Open OCA virtual assistant" aria-expanded="false"><span aria-hidden="true">&#128172;</span></button>
    <section class="oca-chatbot-panel" data-chatbot-panel aria-label="OCA virtual assistant" hidden>
        <header class="oca-chatbot-header"><div><strong>OCA Virtual Assistant</strong><small>Culture and Arts information</small></div><button type="button" class="oca-chatbot-close" data-chatbot-close aria-label="Close assistant">&times;</button></header>
        <div class="oca-chatbot-messages" data-chatbot-messages aria-live="polite"></div>
        <div class="oca-chatbot-faqs" data-chatbot-faqs aria-label="Frequently asked questions"></div>
        <form class="oca-chatbot-form" data-chatbot-form><label class="oca-chatbot-sr-only" for="oca-chatbot-input">Ask about OCA</label><textarea id="oca-chatbot-input" rows="1" maxlength="1000" placeholder="Ask about OCA..." data-chatbot-input></textarea><button type="submit" aria-label="Send message" data-chatbot-send>&#10148;</button></form>
    </section>
</div>
<script src="<?= htmlspecialchars($chatbotBasePath) ?>components/oca-chatbot.js" defer></script>