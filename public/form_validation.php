<?php
/** Odrzuca zły kształt danych przed rzutowaniem ID, trim() i parsowaniem dat. */
function request_has_valid_shape(array $input, bool $allowServiceIds = false): bool
{
    foreach ($input as $key => $value) {
        if ($allowServiceIds && $key === 'service_ids') {
            if (!is_array($value)) {
                return false;
            }
            foreach ($value as $id) {
                if (!is_string($id) || !preg_match('/\A[1-9][0-9]*\z/', $id)) {
                    return false;
                }
            }
            continue;
        }
        if (!is_string($value) || str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8')) {
            return false;
        }
    }
    return true;
}

/** Pola tekstowe przesłane jako tablice traktujemy jako niepoprawne/puste. */
function post_string(string $key): string
{
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
}

/** Wspólna walidacja danych rejestracji i profilu, zgodna z długością kolumn. */
function personal_details_errors(string $name, string $surname, string $phone): array
{
    $errors = [];
    foreach ([[$name, 50, 'Imię'], [$surname, 80, 'Nazwisko']] as [$value, $limit, $label]) {
        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
            || !preg_match("/\A\p{L}[\p{L}\p{M}]*(?:[ '\x{2019}-]\p{L}[\p{L}\p{M}]*)*\z/u", $value)) {
            $errors[] = "$label musi zawierać litery (dopuszczalne spacje, apostrofy i łączniki), maksymalnie $limit znaków.";
        }
    }
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phone) > 20 || !preg_match('/\A\+?[0-9](?:[0-9 -]*[0-9])?\z/', $phone)
        || strlen($digits) < 9 || strlen($digits) > 15) {
        $errors[] = 'Telefon musi zawierać od 9 do 15 cyfr; dopuszczalne są spacje, łączniki i + na początku (maks. 20 znaków).';
    }
    return $errors;
}

function password_validation_error(string $password): ?string
{
    if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 8
        || strlen($password) > 72 || str_contains($password, "\0")) {
        // Bcrypt używany przez PASSWORD_DEFAULT ma limit 72 bajtów.
        return 'Hasło musi mieć co najmniej 8 znaków, maksymalnie 72 bajty UTF-8 i nie może zawierać znaku NUL.';
    }
    return null;
}
