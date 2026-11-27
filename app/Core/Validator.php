<?php
declare(strict_types=1);

namespace App\Core;

class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $parts = explode('|', $ruleStr);
            // A field is compared by numeric value only if declared int/numeric
            // or if the value is already a real number. Otherwise strings are
            // compared by length.
            $isNumericField = in_array('int', $parts, true) || in_array('numeric', $parts, true);

            foreach ($parts as $rule) {
                if ($rule === 'required') {
                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        $errors[$field] = "{$field} is required.";
                        break;
                    }
                }
                if ($value === null || $value === '') {
                    continue;
                }
                if ($rule === 'email' && !filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field] = "{$field} must be a valid email address.";
                } elseif ($rule === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$field] = "{$field} must be an integer.";
                } elseif ($rule === 'numeric' && !is_numeric($value)) {
                    $errors[$field] = "{$field} must be a number.";
                } elseif (str_starts_with($rule, 'min:')) {
                    $min = (int) substr($rule, 4);
                    if (is_int($value) || is_float($value) || ($isNumericField && is_numeric($value))) {
                        if ((float) $value < $min) {
                            $errors[$field] = "{$field} must be at least {$min}.";
                        }
                    } elseif (is_string($value) && mb_strlen($value) < $min) {
                        $errors[$field] = "{$field} must be at least {$min} characters.";
                    }
                } elseif (str_starts_with($rule, 'max:')) {
                    $max = (int) substr($rule, 4);
                    if (is_int($value) || is_float($value) || ($isNumericField && is_numeric($value))) {
                        if ((float) $value > $max) {
                            $errors[$field] = "{$field} must be at most {$max}.";
                        }
                    } elseif (is_string($value) && mb_strlen($value) > $max) {
                        $errors[$field] = "{$field} must be at most {$max} characters.";
                    }
                } elseif (str_starts_with($rule, 'in:')) {
                    $allowed = explode(',', substr($rule, 3));
                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$field] = "{$field} must be one of: " . implode(', ', $allowed) . '.';
                    }
                }
            }
        }
        if (!empty($errors)) {
            Response::error(reset($errors), 422, ['errors' => $errors]);
        }
        return $data;
    }
}