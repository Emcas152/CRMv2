<?php
namespace App\Core;

class Validator
{
    private $data;
    private $rules;
    private $errors = [];

    public function __construct($data, $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public function validate()
    {
        foreach ($this->rules as $field => $rule) {
            $ruleArray = is_array($rule) ? $rule : explode('|', $rule);
            
            foreach ($ruleArray as $singleRule) {
                $this->applyRule($field, $singleRule);
            }
        }

        if (!empty($this->errors)) {
            $errorMessages = [];
            foreach ($this->errors as $field => $messages) {
                $errorMessages[] = implode(', ', $messages);
            }
            throw new \Exception('Validation failed: ' . implode('; ', $errorMessages));
        }

        return true;
    }

    private function applyRule($field, $rule)
    {
        $value = $this->data[$field] ?? null;

        if ($rule === 'required' && empty($value) && $value !== '0') {
            $this->errors[$field][] = "El campo {$field} es requerido";
            return;
        }

        if (empty($value) && $value !== '0') {
            return;
        }

        if ($rule === 'string' && !is_string($value)) {
            $this->errors[$field][] = "El campo {$field} debe ser una cadena de texto";
        }

        if ($rule === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "El campo {$field} debe ser un email válido";
        }

        if ($rule === 'numeric' && !is_numeric($value)) {
            $this->errors[$field][] = "El campo {$field} debe ser numérico";
        }

        if ($rule === 'integer' && !filter_var($value, FILTER_VALIDATE_INT)) {
            $this->errors[$field][] = "El campo {$field} debe ser un entero";
        }

        if ($rule === 'boolean' && !in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            $this->errors[$field][] = "El campo {$field} debe ser booleano";
        }

        if (strpos($rule, 'min:') === 0) {
            $min = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) < $min) {
                $this->errors[$field][] = "El campo {$field} debe tener al menos {$min} caracteres";
            }
            if (is_numeric($value) && $value < $min) {
                $this->errors[$field][] = "El campo {$field} debe ser al menos {$min}";
            }
        }

        if (strpos($rule, 'max:') === 0) {
            $max = (int) substr($rule, 4);
            if (is_string($value) && strlen($value) > $max) {
                $this->errors[$field][] = "El campo {$field} no puede tener más de {$max} caracteres";
            }
            if (is_numeric($value) && $value > $max) {
                $this->errors[$field][] = "El campo {$field} no puede ser mayor a {$max}";
            }
        }

        if (strpos($rule, 'in:') === 0) {
            $allowed = explode(',', substr($rule, 3));
            if (!in_array($value, $allowed)) {
                $this->errors[$field][] = "El campo {$field} debe ser uno de: " . implode(', ', $allowed);
            }
        }

        if (strpos($rule, 'regex:') === 0) {
            $pattern = substr($rule, 6);
            if (!preg_match($pattern, $value)) {
                $this->errors[$field][] = "El campo {$field} tiene un formato inválido";
            }
        }

        if ($rule === 'date') {
            $timestamp = strtotime($value);
            if ($timestamp === false) {
                $this->errors[$field][] = "El campo {$field} debe ser una fecha válida";
            }
        }

        if ($rule === 'url' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = "El campo {$field} debe ser una URL válida";
        }
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public static function make($data, $rules)
    {
        return new self($data, $rules);
    }
}
