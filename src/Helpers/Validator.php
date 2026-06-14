<?php

namespace App\Helpers;

/**
 * Validation helper for form input validation
 */
class Validator
{
    private array $data;
    private array $errors = [];
    private array $rules = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Set validation rules
     */
    public function rules(array $rules): self
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * Validate the data against rules
     */
    public function validate(): bool
    {
        foreach ($this->rules as $field => $ruleSet) {
            $ruleArray = explode('|', $ruleSet);
            
            foreach ($ruleArray as $rule) {
                $this->applyRule($field, $rule);
            }
        }

        return empty($this->errors);
    }

    /**
     * Apply a single validation rule
     */
    private function applyRule(string $field, string $rule): void
    {
        $value = $this->data[$field] ?? null;
        $parts = explode(':', $rule);
        $ruleName = $parts[0];
        $params = isset($parts[1]) ? explode(',', $parts[1]) : [];

        switch ($ruleName) {
            case 'required':
                if (empty($value) && $value !== '0') {
                    $this->addError($field, ucfirst($field) . ' is required');
                }
                break;

            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, ucfirst($field) . ' must be a valid email address');
                }
                break;

            case 'min':
                $min = (int) $params[0];
                if (strlen($value) < $min) {
                    $this->addError($field, ucfirst($field) . " must be at least {$min} characters");
                }
                break;

            case 'max':
                $max = (int) $params[0];
                if (strlen($value) > $max) {
                    $this->addError($field, ucfirst($field) . " must not exceed {$max} characters");
                }
                break;

            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->addError($field, ucfirst($field) . ' must be a number');
                }
                break;

            case 'integer':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_INT)) {
                    $this->addError($field, ucfirst($field) . ' must be an integer');
                }
                break;

            case 'between':
                $min = (int) $params[0];
                $max = (int) $params[1];
                if (is_numeric($value) && ($value < $min || $value > $max)) {
                    $this->addError($field, ucfirst($field) . " must be between {$min} and {$max}");
                }
                break;

            case 'in':
                $allowed = $params;
                if (!empty($value) && !in_array($value, $allowed)) {
                    $this->addError($field, ucfirst($field) . ' must be one of: ' . implode(', ', $allowed));
                }
                break;

            case 'date':
                if (!empty($value) && strtotime($value) === false) {
                    $this->addError($field, ucfirst($field) . ' must be a valid date');
                }
                break;

            case 'url':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    $this->addError($field, ucfirst($field) . ' must be a valid URL');
                }
                break;

            case 'regex':
                $pattern = $params[0];
                if (!empty($value) && !preg_match($pattern, $value)) {
                    $this->addError($field, ucfirst($field) . ' has an invalid format');
                }
                break;

            case 'confirmed':
                $confirmField = $field . '_confirmation';
                if (isset($this->data[$confirmField]) && $value !== $this->data[$confirmField]) {
                    $this->addError($field, ucfirst($field) . ' confirmation does not match');
                }
                break;

            case 'alpha':
                if (!empty($value) && !ctype_alpha($value)) {
                    $this->addError($field, ucfirst($field) . ' may only contain letters');
                }
                break;

            case 'alpha_num':
                if (!empty($value) && !ctype_alnum($value)) {
                    $this->addError($field, ucfirst($field) . ' may only contain letters and numbers');
                }
                break;
        }
    }

    /**
     * Add an error message
     */
    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    /**
     * Get all errors
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Get errors for a specific field
     */
    public function getFieldErrors(string $field): array
    {
        return $this->errors[$field] ?? [];
    }

    /**
     * Check if validation passed
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    /**
     * Check if validation failed
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Get first error message
     */
    public function firstError(): ?string
    {
        foreach ($this->errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return $fieldErrors[0];
            }
        }
        return null;
    }

    /**
     * Get sanitized data
     */
    public function sanitized(): array
    {
        $sanitized = [];
        
        foreach ($this->data as $key => $value) {
            if (is_string($value)) {
                $sanitized[$key] = htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }
}
