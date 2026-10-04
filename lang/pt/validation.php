<?php

// Subset in use; extend as new rules appear.
return [
    'alpha_dash' => 'O campo :attribute só pode conter letras, números, hífenes e sublinhados.',
    'boolean' => 'O campo :attribute tem de ser verdadeiro ou falso.',
    'email' => 'O campo :attribute tem de ser um endereço de email válido.',
    'enum' => 'O valor seleccionado para :attribute é inválido.',
    'exists' => 'O valor seleccionado para :attribute é inválido.',
    'integer' => 'O campo :attribute tem de ser um número inteiro.',
    'lowercase' => 'O campo :attribute tem de estar em minúsculas.',
    'max' => [
        'string' => 'O campo :attribute não pode ter mais de :max caracteres.',
    ],
    'min' => [
        'string' => 'O campo :attribute tem de ter pelo menos :min caracteres.',
    ],
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute tem de ser texto.',
    'unique' => 'Este :attribute já está em uso.',

    'attributes' => [
        'name' => 'nome',
        'email' => 'email',
        'password' => 'palavra-passe',
        'role' => 'papel',
        'department_id' => 'departamento',
        'parent_id' => 'departamento superior',
        'slug' => 'identificador',
        'is_active' => 'activo',
    ],
];
