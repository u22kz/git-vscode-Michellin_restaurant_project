<?php
// Wycofany formularz. Nie przekazujemy żądań POST do mechanizmu rezerwacji.
http_response_code(410);
header('Content-Type: text/plain; charset=UTF-8');
exit('Ten formularz został wycofany. Otwórz dashboard_client.php.');
