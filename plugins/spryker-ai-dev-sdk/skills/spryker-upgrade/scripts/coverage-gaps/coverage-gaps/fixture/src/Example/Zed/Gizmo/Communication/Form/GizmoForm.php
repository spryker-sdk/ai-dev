<?php

namespace Example\Zed\Gizmo\Communication\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

// Without --skip-ui-definitions all methods except getBlockPrefix() (declarative) are reported.
// With it, only addTypeField() (has logic) and normalizeName() (not a definition) stay.
class GizmoForm extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addNameField($builder)->addTypeField($builder, $options['required'] ?? false);
    }

    public function getBlockPrefix(): string
    {
        return 'gizmo';
    }

    public function addNameField(FormBuilderInterface $builder): static
    {
        $builder->add('name', TextType::class);

        return $this;
    }

    public function addTypeField(FormBuilderInterface $builder, bool $required): static
    {
        if ($required) {
            $builder->add('type', TextType::class);
        }

        return $this;
    }

    public function normalizeName(string $name): string
    {
        return trim($name);
    }
}
