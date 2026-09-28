<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\ValidationException;

/**
 * Validaciones simples y explícitas. Acumula errores por campo y lanza ValidationException.
 */
final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function make(array $data): self
    {
        return new self($data);
    }

    public function string(string $field): string
    {
        $value = $this->data[$field] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    public function required(string $field, string $label): self
    {
        if ($this->string($field) === '') {
            $this->errors[$field] ??= sprintf('%s es obligatorio.', $label);
        }

        return $this;
    }

    public function email(string $field, string $label = 'El correo'): self
    {
        $value = $this->string($field);
        if ($value !== '' && !self::isEmail($value)) {
            $this->errors[$field] ??= sprintf('%s no tiene un formato válido.', $label);
        }

        return $this;
    }

    public function maxLength(string $field, int $max, string $label): self
    {
        if (mb_strlen($this->string($field)) > $max) {
            $this->errors[$field] ??= sprintf('%s no puede superar %d caracteres.', $label, $max);
        }

        return $this;
    }

    public function password(string $field, ?string $confirmationField = null): self
    {
        $value = (string) ($this->data[$field] ?? '');
        if (mb_strlen($value) < 8) {
            $this->errors[$field] ??= 'La contraseña debe tener al menos 8 caracteres.';
        } elseif (mb_strlen($value) > 200) {
            $this->errors[$field] ??= 'La contraseña es demasiado larga.';
        }
        if ($confirmationField !== null && $value !== (string) ($this->data[$confirmationField] ?? '')) {
            $this->errors[$confirmationField] ??= 'Las contraseñas no coinciden.';
        }

        return $this;
    }

    /**
     * @param list<string> $allowed
     */
    public function in(string $field, array $allowed, string $label): self
    {
        if (!in_array($this->string($field), $allowed, true)) {
            $this->errors[$field] ??= sprintf('%s no es válido.', $label);
        }

        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function validate(): void
    {
        if ($this->errors !== []) {
            throw new ValidationException(reset($this->errors), 'validation_failed', ['fields' => $this->errors]);
        }
    }

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 190 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function normalizeEmail(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Separa una lista de correos por comas, punto y coma, espacios o saltos de línea.
     *
     * @return list<string>
     */
    public static function splitEmails(string $value): array
    {
        $parts = preg_split('/[\s,;]+/', $value) ?: [];
        $emails = [];
        foreach ($parts as $part) {
            $part = self::normalizeEmail(trim($part, " <>\"'"));
            if ($part !== '') {
                $emails[$part] = true;
            }
        }

        return array_keys($emails);
    }
}
