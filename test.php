<?php
echo 'Controller directory exists: ' . (is_dir('./controllers') ? 'Yes' : 'No');
echo '<br>AuthController.php exists: ' . (file_exists('./controllers/AuthController.php') ? 'Yes' : 'No');