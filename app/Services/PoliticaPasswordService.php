<?php

namespace App\Services;

/**
 * Politica de contrasenas (guia NIST 800-63B: mas importante el largo y no
 * ser predecible que exigir simbolos). Se aplica al cambiar la contrasena,
 * al fijar una manual desde Usuarios/Empleados y al crear el Administrador
 * inicial. Las contrasenas temporales que genera el sistema (12 hex)
 * no pasan por aqui.
 */
class PoliticaPasswordService
{
    public const MIN_CARACTERES = 10;

    /** bcrypt solo usa los primeros 72 bytes: mas largo se truncaria sin avisar. */
    public const MAX_BYTES = 72;

    /** Palabras (solo letras) y secuencias que nunca deben ser la contrasena. */
    private const COMUNES = [
        'password', 'passw0rd', 'contrasena', 'clave', 'admin', 'administrador', 'administrator', 'root', 'qwerty', 'qwertyuiop',
        'asdfgh', 'asdfghjkl', 'zxcvbn', 'abcdef', 'abcdefgh', 'letmein', 'welcome', 'bienvenido', 'bienvenida', 'temporal',
        'inalde', 'horarios', 'unisabana', 'sabana', 'colombia', 'bogota', 'cambiame', 'cambiar', 'iloveyou', 'monkey', 'dragon',
        'football', 'futbol', 'secreto', 'secret', 'usuario', 'empleado', 'test', 'prueba', 'changeme', 'default', 'login',
    ];

    private const SECUENCIAS = [
        '01234567890123456789', '98765432109876543210', 'abcdefghijklmnopqrstuvwxyz', 'zyxwvutsrqponmlkjihgfedcba',
        'qwertyuiopasdfghjklzxcvbnm', 'qazwsxedcrfvtgbyhnujmikolp',
    ];

    /**
     * @param string|null $email  correo de la cuenta (la contrasena no puede contener su parte local)
     * @param string|null $hashActual hash vigente: la nueva no puede ser igual a la actual
     * @return string|null mensaje de error, o null si la contrasena es aceptable
     */
    public static function validar(string $password, ?string $email = null, ?string $hashActual = null): ?string
    {
        if (mb_strlen($password) < self::MIN_CARACTERES) {
            return 'La contrasena debe tener al menos ' . self::MIN_CARACTERES . ' caracteres.';
        }

        if (strlen($password) > self::MAX_BYTES) {
            return 'La contrasena es demasiado larga (maximo ' . self::MAX_BYTES . ' caracteres).';
        }

        $minuscula = mb_strtolower($password);

        if (count(array_unique(preg_split('//u', $minuscula, -1, PREG_SPLIT_NO_EMPTY))) < 4) {
            return 'La contrasena es demasiado repetitiva: combina mas caracteres distintos.';
        }

        foreach (self::SECUENCIAS as $secuencia) {
            if (str_contains($secuencia, $minuscula)) {
                return 'La contrasena es una secuencia de teclado o numerica muy comun.';
            }
        }

        // Palabra comun, aunque le agreguen unos pocos numeros o simbolos ("Password2026!",
        // "C0ntrasena123"): se compara con las letras sueltas y con la version "leet" (0=o, 3=e...).
        $variantes = [
            preg_replace('/[^a-z]/', '', $minuscula),
            preg_replace('/[^a-z]/', '', strtr($minuscula, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '@' => 'a', '$' => 's'])),
        ];
        foreach ($variantes as $letras) {
            foreach (self::COMUNES as $palabra) {
                $resto = strlen($letras) - strlen($palabra);
                if ($resto >= 0 && $resto <= 4 && (str_starts_with($letras, $palabra) || str_ends_with($letras, $palabra))) {
                    return 'La contrasena es una palabra demasiado comun; elige una frase que solo tu conozcas.';
                }
            }
        }

        if ($email !== null && $email !== '') {
            $local = mb_strtolower(explode('@', $email)[0]);
            if (mb_strlen($local) >= 4 && str_contains($minuscula, $local)) {
                return 'La contrasena no puede contener tu correo.';
            }
        }

        if ($hashActual !== null && $hashActual !== '' && password_verify($password, $hashActual)) {
            return 'La nueva contrasena debe ser distinta de la actual.';
        }

        return null;
    }
}
