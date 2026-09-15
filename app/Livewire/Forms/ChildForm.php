<?php

namespace App\Livewire\Forms;

use App\Models\Child;
use App\Models\ParentChild;
use Illuminate\Validation\Rule;
use Livewire\Form;

/** Formulaire administratif de création / modification d'un élève (lot 1 §4, §6). */
class ChildForm extends Form
{
    public ?int $id = null;
    public string $name = '';
    public string $classe = '';
    public ?string $birth_date = null;
    public string $gender = '';
    public string $email = '';
    public string $password = '';

    public string $parent_email = '';
    public string $parent_name = '';
    public string $relation = 'mere';
    public bool $consent = false;

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:150'],
            'classe'       => ['required', 'string', 'max:50'],
            'birth_date'   => [$this->id ? 'nullable' : 'required', 'date', 'before:today'],
            'gender'       => ['nullable', 'in:m,f,x'],
            'email'        => ['required', 'email', 'max:150', Rule::unique('children', 'email')->ignore($this->id)],
            'password'     => [$this->id ? 'nullable' : 'nullable', 'string', 'min:6', 'max:72'],
            'parent_email' => [$this->id ? 'nullable' : 'required', 'email', 'max:150'],
            'parent_name'  => ['nullable', 'string', 'max:150'],
            'relation'     => ['required', 'in:' . implode(',', ParentChild::RELATIONS)],
            'consent'      => ['boolean'],
        ];
    }

    public function validationAttributes(): array
    {
        return [
            'name' => 'nom', 'classe' => 'classe', 'birth_date' => 'date de naissance', 'gender' => 'genre',
            'email' => 'identifiant de connexion', 'password' => 'mot de passe', 'parent_email' => 'e-mail du parent',
            'parent_name' => 'nom du parent', 'relation' => 'relation', 'consent' => 'consentement',
        ];
    }

    public function fillFromChild(Child $child): void
    {
        $this->id         = $child->id;
        $this->name       = $child->name;
        $this->classe     = $child->classe;
        $this->birth_date = $child->birth_date?->toDateString();
        $this->gender     = (string) $child->gender;
        $this->email      = $child->email;
        $this->password   = '';
        $this->parent_email = '';
        $this->parent_name  = '';
        $this->relation   = 'mere';
        $this->consent    = false;
    }
}
