<?php

/*
 * This file is part of the Panther project.
 *
 * (c) Kévin Dunglas <kevin@dunglas.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

require __DIR__.'/security-check.php';

if (isset($_GET['redirect'])) {
    header('Location: /response.php?code='.(int) $_GET['redirect'], true, 302);
    exit;
}

http_response_code((int) ($_GET['code'] ?? 200));
header('X-Panther-Method: '.$_SERVER['REQUEST_METHOD']);
setcookie('first', '1');
setcookie('second', '2');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Response</title>
</head>
<body>
    <p id="code"><?php echo (int) ($_GET['code'] ?? 200); ?></p>
    <a href="/response.php?code=404">Not found</a>
    <a href="/response.php?redirect=201">Redirect</a>
    <form method="post" action="/response.php?code=422">
        <input name="field" value="value">
        <button type="submit">Submit</button>
    </form>
</body>
</html>
