<?php


namespace SilverStripe\GraphQL\Schema\Type;

use SilverStripe\GraphQL\Schema\Exception\SchemaBuilderException;
use SilverStripe\GraphQL\Schema\Field\Field;
use SilverStripe\GraphQL\Schema\Field\ModelAware;
use SilverStripe\GraphQL\Schema\Field\ModelField;
use SilverStripe\GraphQL\Schema\Interfaces\BaseFieldsProvider;
use SilverStripe\GraphQL\Schema\Interfaces\DefaultFieldsProvider;
use SilverStripe\GraphQL\Schema\Interfaces\ExtraTypeProvider;
use SilverStripe\GraphQL\Schema\Interfaces\InputTypeProvider;
use SilverStripe\GraphQL\Schema\Interfaces\ModelBlacklist;
use SilverStripe\GraphQL\Schema\Interfaces\OperationCreator;
use SilverStripe\GraphQL\Schema\Interfaces\OperationProvider;
use SilverStripe\GraphQL\Schema\Interfaces\SchemaModelInterface;
use SilverStripe\GraphQL\Schema\Schema;
use SilverStripe\ORM\ArrayLib;

/**
 * A type that is generated by a model
 */
class ModelType extends Type implements ExtraTypeProvider
{
    use ModelAware;

    /**
     * @var array
     */
    private $operationCreators = [];

    /**
     * @var Type[]
     */
    private $extraTypes = [];

    /**
     * @var array
     */
    private $blacklistedFields = [];

    /**
     * @var array
     */
    private $operations = [];


    /**
     * ModelType constructor.
     * @param array $config
     * @param SchemaModelInterface|null $model
     * @throws SchemaBuilderException
     */
    public function __construct(SchemaModelInterface $model, array $config = [])
    {
        $this->setModel($model);
        $type = $this->getModel()->getTypeName();
        Schema::invariant(
            $type,
            'Could not determine type for model %s',
            $this->getModel()->getSourceClass()
        );

        /* @var SchemaModelInterface&ModelBlacklist $model */
        $this->blacklistedFields = $model instanceof ModelBlacklist ?
            array_map('strtolower', $model->getBlacklistedFields()) :
            [];

        parent::__construct($type);

        $this->applyConfig($config);
    }

    /**
     * @param array $config
     * @throws SchemaBuilderException
     */
    public function applyConfig(array $config)
    {
        Schema::assertValidConfig($config, ['fields', 'operations', 'plugins']);

        $fieldConfig = $config['fields'] ?? [];
        if ($fieldConfig === Schema::ALL) {
            $this->addAllFields();
        } else {
            $fields = array_merge($this->getInitialFields(), $fieldConfig);
            Schema::assertValidConfig($fields);
            if (isset($fields[Schema::ALL])) {
                $all = $fields[Schema::ALL];
                unset($fields[Schema::ALL]);
                $fields = array_merge(
                    [
                    Schema::ALL => $all,
                    ],
                    $fields
                );
            }
            foreach ($fields as $fieldName => $data) {
                if ($data === false) {
                    unset($this->fields[$fieldName]);
                    continue;
                }
                // Allow * as a field, so you can override a subset of fields
                if ($fieldName === Schema::ALL) {
                    $this->addAllFields();
                } else {
                    $this->addField($fieldName, $data);
                }
            }
        }

        $operations = $config['operations'] ?? null;
        if ($operations) {
            if ($operations === Schema::ALL) {
                $this->addAllOperations();
            } else {
                $this->applyOperationsConfig($operations);
            }
        }

        if (isset($config['plugins'])) {
            $this->setPlugins($config['plugins']);
        }
    }

    /**
     * @param string $fieldName
     * @param array|string|Field|boolean $fieldConfig
     * @param callable|null $callback
     * @return Type
     * @throws SchemaBuilderException
     */
    public function addField(string $fieldName, $fieldConfig = true, ?callable $callback = null): Type
    {
        $fieldObj = null;
        if ($fieldConfig instanceof ModelField) {
            $fieldObj = $fieldConfig;
        } else {
            $field = ModelField::create($fieldName, $fieldConfig, $this->getModel());
            $fieldObj = $this->getModel()->getField(
                $field->getPropertyName(),
                is_array($fieldConfig) ? $fieldConfig : []
            );
            if ($fieldObj) {
                $fieldObj->setName($field->getName());
                if (is_array($fieldConfig)) {
                    $fieldObj->applyConfig($fieldConfig);
                } elseif (is_string($fieldConfig)) {
                    $fieldObj->setType($fieldConfig);
                }
            } else {
                $fieldObj = ModelField::create($fieldName, $fieldConfig, $this->getModel());
                Schema::invariant(
                    $fieldObj->getType(),
                    'Field %s on type %s could not infer a type. Check to see if the field exists on the model
                    or provide an explicit type if necessary.',
                    $fieldObj->getName(),
                    $this->getName()
                );
            }
        }
        Schema::invariant(
            $fieldObj,
            'Could not get field "%s" on "%s"',
            $fieldName,
            $this->getName()
        );

        Schema::invariant(
            !in_array(strtolower($fieldObj->getName() ?? ''), $this->blacklistedFields ?? []),
            'Field %s is not allowed on %s',
            $fieldObj->getName(),
            $this->getModel()->getSourceClass()
        );

        $this->fields[$fieldObj->getName()] = $fieldObj;
        if ($callback) {
            call_user_func_array($callback, [$fieldObj]);
        }
        return $this;
    }

    /**
     * @param array $fields
     * @return $this
     * @throws SchemaBuilderException
     */
    public function addFields(array $fields): self
    {
        if (ArrayLib::is_associative($fields)) {
            foreach ($fields as $fieldName => $config) {
                $this->addField($fieldName, $config);
            }
        } else {
            foreach ($fields as $fieldName) {
                $this->addField($fieldName);
            }
        }

        return $this;
    }

    /**
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function addAllFields(): self
    {
        $initialFields = $this->getInitialFields();
        foreach ($initialFields as $fieldName => $fieldType) {
            $this->addField($fieldName, $fieldType);
        }
        $allFields = $this->getModel()->getAllFields();
        foreach ($allFields as $fieldName) {
            if (!$this->getFieldByName($fieldName)) {
                $this->addField($fieldName, $this->getModel()->getField($fieldName));
            }
        }
        return $this;
    }

    /**
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function addAllOperations(): self
    {
        Schema::invariant(
            $this->getModel() instanceof OperationProvider,
            'Model for %s does not implement %s. No operations are allowed',
            $this->getName(),
            OperationProvider::class
        );
        /* @var SchemaModelInterface&OperationProvider $model */
        $model = $this->getModel();

        $operations = [];
        foreach ($model->getAllOperationIdentifiers() as $id) {
            $operations[$id] = true;
        }
        $this->applyOperationsConfig($operations);

        return $this;
    }


    /**
     * @param array $operations
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function applyOperationsConfig(array $operations): ModelType
    {
        Schema::assertValidConfig($operations);
        foreach ($operations as $operationName => $data) {
            if ($data === false) {
                unset($this->operationCreators[$operationName]);
                continue;
            }
            // Allow * as an operation so individual operations can be overridden
            if ($operationName === Schema::ALL) {
                $this->addAllOperations();
                continue;
            }
            Schema::invariant(
                is_array($data) || $data === true,
                'Operation data for %s must be a map of config or true for a generic implementation',
                $operationName
            );

            $config = ($data === true) ? [] : $data;
            $this->addOperation($operationName, $config);
        }

        return $this;
    }

    /**
     * @param string $fieldName
     * @return Field|null
     */
    public function getFieldByName(string $fieldName): ?Field
    {
        /* @var ModelField $fieldObj */
        foreach ($this->getFields() as $fieldObj) {
            if ($fieldObj->getName() === $fieldName) {
                return $fieldObj;
            }
        }
        return null;
    }

    /**
     * @param Type $type
     * @return Type
     * @throws SchemaBuilderException
     */
    public function mergeWith(Type $type): Type
    {
        if ($type instanceof ModelType) {
            foreach ($type->getOperationCreators() as $name => $config) {
                $this->addOperation($name, $config);
            }
        }

        return parent::mergeWith($type);
    }

    /**
     * @param string $operationName
     * @param array $config
     * @return ModelType
     */
    public function addOperation(string $operationName, array $config = []): self
    {
        $this->operationCreators[$operationName] = $config;

        return $this;
    }

    /**
     * @param string $operationName
     * @return ModelType
     */
    public function removeOperation(string $operationName): self
    {
        unset($this->operationCreators[$operationName]);

        return $this;
    }


    /**
     * @param string $operationName
     * @param array $config
     * @return ModelType
     * @throws SchemaBuilderException
     */
    public function updateOperation(string $operationName, array $config = []): self
    {
        Schema::invariant(
            isset($this->operationCreators[$operationName]),
            'Cannot update nonexistent operation %s on %s',
            $operationName,
            $this->getName()
        );

        $this->operationCreators[$operationName] = array_merge(
            $this->operationCreators[$operationName],
            $config
        );

        return $this;
    }

    /**
     * @throws SchemaBuilderException
     */
    public function buildOperations(): void
    {
        $operations = [];
        foreach ($this->operationCreators as $operationName => $config) {
            $operationCreator = $this->getOperationCreator($operationName);
            $operation = $operationCreator->createOperation(
                $this->getModel(),
                $this->getName(),
                $config
            );
            if ($operation) {
                if ($operation instanceof Field) {
                    $operationsConfig = $this->getModel()
                        ->getModelConfiguration()
                        ->getOperationConfig($operationName);
                    $defaultPlugins = $operationsConfig['plugins'] ?? [];
                    $operation->setDefaultPlugins($defaultPlugins);
                }
                $operations[$operationName] = $operation;
            }
        }

        $this->operations = $operations;
    }

    /**
     * @return array
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * @return array
     */
    public function getOperationCreators(): array
    {
        return $this->operationCreators;
    }

    /**
     * @return Type[]
     * @throws SchemaBuilderException
     */
    public function getExtraTypes(): array
    {
        $extraTypes = $this->extraTypes;
        foreach ($this->operationCreators as $operationName => $config) {
            $operationCreator = $this->getOperationCreator($operationName);
            if (!$operationCreator instanceof InputTypeProvider) {
                continue;
            }
            $types = $operationCreator->provideInputTypes($this, $config);
            foreach ($types as $type) {
                Schema::invariant(
                    $type instanceof InputType,
                    'Input types must be instances of %s on %s',
                    InputType::class,
                    $this->getName()
                );
                $extraTypes[] = $type;
            }
        }
        foreach ($this->getFields() as $field) {
            if (!$field instanceof ModelField) {
                continue;
            }
            if ($modelType = $field->getModelType()) {
                $extraTypes = array_merge($extraTypes, $modelType->getExtraTypes());
                $extraTypes[] = $modelType;
            }
        }
        if ($this->getModel() instanceof ExtraTypeProvider) {
            $extraTypes = array_merge($extraTypes, $this->getModel()->getExtraTypes());
        }

        return $extraTypes;
    }

    /**
     * @throws SchemaBuilderException
     */
    public function validate(): void
    {
        if ($this->getModel() instanceof BaseFieldsProvider) {
            foreach ($this->getModel()->getBaseFields() as $fieldName => $data) {
                Schema::invariant(
                    $this->getFieldByName($fieldName),
                    'Required base field %s was not on type %s',
                    $fieldName,
                    $this->getName()
                );
            }
        }
        parent::validate();
    }

    /**
     * @return array
     */
    private function getInitialFields(): array
    {
        $model = $this->getModel();
        /* @var SchemaModelInterface&DefaultFieldsProvider $model */
        $default = $model instanceof DefaultFieldsProvider ? $model->getDefaultFields() : [];
        $base = $model instanceof BaseFieldsProvider ? $model->getBaseFields() : [];

        return array_merge($default, $base);
    }

    /**
     * @param string $operationName
     * @return OperationCreator
     * @throws SchemaBuilderException
     */
    private function getOperationCreator(string $operationName): OperationCreator
    {
        $operationCreator = $this->getModel()->getOperationCreatorByIdentifier($operationName);
        Schema::invariant($operationCreator, 'Invalid operation: %s', $operationName);

        return $operationCreator;
    }
}
