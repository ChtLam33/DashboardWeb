<?php
// auth_lib.php deja charge par auto_protect.php (auto_prepend_file)
logoutUser();
header('Location: /');
exit;
