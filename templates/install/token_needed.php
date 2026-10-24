<?php /** @var string $token_file */ ?>
<h1>Set an install token first</h1>
<p>To make sure only you can install this site, create a one-time install token on the server, then reload this page and enter it in the first step.</p>
<p>In a terminal on the server, from the site folder:</p>
<pre><code>php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' &gt; storage/install-token</code></pre>
<p class="muted">The file is <code><?= e($token_file) ?></code>; it must be readable by the web server. You can also set the environment variable <code>GATHER_INSTALL_TOKEN</code> instead. The file is deleted automatically when the installation finishes.</p>
