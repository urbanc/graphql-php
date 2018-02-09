<?php
namespace GraphQL\Utils;

use GraphQL\Error\Error;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\DirectiveDefinitionNode;
use GraphQL\Language\AST\EnumTypeDefinitionNode;
use GraphQL\Language\AST\EnumValueDefinitionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use GraphQL\Language\AST\InputObjectTypeDefinitionNode;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\ListTypeNode;
use GraphQL\Language\AST\NamedTypeNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\AST\NonNullTypeNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use GraphQL\Language\AST\ScalarTypeDefinitionNode;
use GraphQL\Language\AST\TypeNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Token;
use GraphQL\Type\Definition\CustomScalarType;
use GraphQL\Type\Definition\EnumType;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\InputType;
use GraphQL\Type\Definition\OutputType;
use GraphQL\Type\Definition\Directive;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\FieldArgument;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Type\Introspection;

class ASTDefinitionBuilder
{

    /**
     * @var array
     */
    private $typeDefintionsMap;

    /**
     * @var array
     */
    private $options;

    /**
     * @var callable
     */
    private $resolveType;

    /**
     * @var array
     */
    private $cache;

    public function __construct(array $typeDefintionsMap, $options, callable $resolveType)
    {
        $this->typeDefintionsMap = $typeDefintionsMap;
        $this->options = $options;
        $this->resolveType = $resolveType;

        $this->cache = [
            'String' => Type::string(),
            'Int' => Type::int(),
            'Float' => Type::float(),
            'Boolean' => Type::boolean(),
            'ID' => Type::id(),
            '__Schema' => Introspection::_schema(),
            '__Directive' => Introspection::_directive(),
            '__DirectiveLocation' => Introspection::_directiveLocation(),
            '__Type' => Introspection::_type(),
            '__Field' => Introspection::_field(),
            '__InputValue' => Introspection::_inputValue(),
            '__EnumValue' => Introspection::_enumValue(),
            '__TypeKind' => Introspection::_typeKind(),
        ];
    }

    /**
     * @param Type $innerType
     * @param TypeNode|ListTypeNode|NonNullTypeNode $inputTypeNode
     * @return Type
     */
    private function buildWrappedType(Type $innerType, TypeNode $inputTypeNode)
    {
        if ($inputTypeNode->kind == NodeKind::LIST_TYPE) {
            return Type::listOf($this->buildWrappedType($innerType, $inputTypeNode->type));
        }
        if ($inputTypeNode->kind == NodeKind::NON_NULL_TYPE) {
            $wrappedType = $this->buildWrappedType($innerType, $inputTypeNode->type);
            Utils::invariant(!($wrappedType instanceof NonNull), 'No nesting nonnull.');
            return Type::nonNull($wrappedType);
        }
        return $innerType;
    }

    /**
     * @param TypeNode|ListTypeNode|NonNullTypeNode $typeNode
     * @return TypeNode
     */
    private function getNamedTypeNode(TypeNode $typeNode)
    {
        $namedType = $typeNode;
        while ($namedType->kind === NodeKind::LIST_TYPE || $namedType->kind === NodeKind::NON_NULL_TYPE) {
            $namedType = $namedType->type;
        }
        return $namedType;
    }

    /**
     * @param string $typeName
     * @param NamedTypeNode|null $typeNode
     * @return Type
     * @throws Error
     */
    private function internalBuildType($typeName, $typeNode = null) {
        if (!isset($this->cache[$typeName])) {
            if (isset($this->typeDefintionsMap[$typeName])) {
                $this->cache[$typeName] = $this->makeSchemaDef($this->typeDefintionsMap[$typeName]);
            } else {
                $fn = $this->resolveType;
                $this->cache[$typeName] = $fn($typeName, $typeNode);
            }
        }

        return $this->cache[$typeName];
    }

    /**
     * @param string|NamedTypeNode $ref
     * @return Type
     * @throws Error
     */
    public function buildType($ref)
    {
        if (is_string($ref)) {
            return $this->internalBuildType($ref);
        }

        return $this->internalBuildType($ref->name->value, $ref);
    }

    /**
     * @param TypeNode $typeNode
     * @return InputType|Type
     * @throws Error
     */
    public function buildInputType(TypeNode $typeNode)
    {
        $type = $this->internalBuildWrappedType($typeNode);
        Utils::invariant(Type::isInputType($type), 'Expected Input type.');
        return $type;
    }

    /**
     * @param TypeNode $typeNode
     * @return OutputType|Type
     * @throws Error
     */
    public function buildOutputType(TypeNode $typeNode)
    {
        $type = $this->internalBuildWrappedType($typeNode);
        Utils::invariant(Type::isOutputType($type), 'Expected Output type.');
        return $type;
    }

    /**
     * @param TypeNode|string $typeNode
     * @return ObjectType|Type
     * @throws Error
     */
    public function buildObjectType($typeNode)
    {
        $type = $this->buildType($typeNode);
        Utils::invariant($type instanceof ObjectType, 'Expected Object type.' . get_class($type));
        return $type;
    }

    /**
     * @param TypeNode|string $typeNode
     * @return InterfaceType|Type
     * @throws Error
     */
    public function buildInterfaceType($typeNode)
    {
        $type = $this->buildType($typeNode);
        Utils::invariant($type instanceof InterfaceType, 'Expected Interface type.');
        return $type;
    }

    /**
     * @param TypeNode $typeNode
     * @return Type
     * @throws Error
     */
    private function internalBuildWrappedType(TypeNode $typeNode)
    {
        $typeDef = $this->buildType($this->getNamedTypeNode($typeNode));
        return $this->buildWrappedType($typeDef, $typeNode);
    }

    public function buildDirective(DirectiveDefinitionNode $directiveNode)
    {
        return new Directive([
            'name' => $directiveNode->name->value,
            'description' => $this->getDescription($directiveNode),
            'locations' => Utils::map($directiveNode->locations, function ($node) {
                return $node->value;
            }),
            'args' => $directiveNode->arguments ? FieldArgument::createMap($this->makeInputValues($directiveNode->arguments)) : null,
            'astNode' => $directiveNode,
        ]);
    }

    public function buildField(FieldDefinitionNode $field)
    {
        return [
            'type' => $this->buildOutputType($field->type),
            'description' => $this->getDescription($field),
            'args' => $this->makeInputValues($field->arguments),
            'deprecationReason' => $this->getDeprecationReason($field),
            'astNode' => $field
        ];
    }

    private function makeSchemaDef($def)
    {
        if (!$def) {
            throw new Error('def must be defined.');
        }
        switch ($def->kind) {
            case NodeKind::OBJECT_TYPE_DEFINITION:
                return $this->makeTypeDef($def);
            case NodeKind::INTERFACE_TYPE_DEFINITION:
                return $this->makeInterfaceDef($def);
            case NodeKind::ENUM_TYPE_DEFINITION:
                return $this->makeEnumDef($def);
            case NodeKind::UNION_TYPE_DEFINITION:
                return $this->makeUnionDef($def);
            case NodeKind::SCALAR_TYPE_DEFINITION:
                return $this->makeScalarDef($def);
            case NodeKind::INPUT_OBJECT_TYPE_DEFINITION:
                return $this->makeInputObjectDef($def);
            default:
                throw new Error("Type kind of {$def->kind} not supported.");
        }
    }

    private function makeTypeDef(ObjectTypeDefinitionNode $def)
    {
        $typeName = $def->name->value;
        return new ObjectType([
            'name' => $typeName,
            'description' => $this->getDescription($def),
            'fields' => function () use ($def) {
                return $this->makeFieldDefMap($def);
            },
            'interfaces' => function () use ($def) {
                return $this->makeImplementedInterfaces($def);
            },
            'astNode' => $def
        ]);
    }

    private function makeFieldDefMap($def)
    {
        return Utils::keyValMap(
            $def->fields,
            function ($field) {
                return $field->name->value;
            },
            function ($field) {
                return $this->buildField($field);
            }
        );
    }

    private function makeImplementedInterfaces(ObjectTypeDefinitionNode $def)
    {
        if (isset($def->interfaces)) {
            return Utils::map($def->interfaces, function ($iface) {
                return $this->buildInterfaceType($iface);
            });
        }
        return null;
    }

    private function makeInputValues($values)
    {
        return Utils::keyValMap(
            $values,
            function ($value) {
                return $value->name->value;
            },
            function ($value) {
                $type = $this->buildInputType($value->type);
                $config = [
                    'name' => $value->name->value,
                    'type' => $type,
                    'description' => $this->getDescription($value),
                    'astNode' => $value
                ];
                if (isset($value->defaultValue)) {
                    $config['defaultValue'] = AST::valueFromAST($value->defaultValue, $type);
                }
                return $config;
            }
        );
    }

    private function makeInterfaceDef(InterfaceTypeDefinitionNode $def)
    {
        $typeName = $def->name->value;
        return new InterfaceType([
            'name' => $typeName,
            'description' => $this->getDescription($def),
            'fields' => function () use ($def) {
                return $this->makeFieldDefMap($def);
            },
            'astNode' => $def
        ]);
    }

    private function makeEnumDef(EnumTypeDefinitionNode $def)
    {
        return new EnumType([
            'name' => $def->name->value,
            'description' => $this->getDescription($def),
            'astNode' => $def,
            'values' => Utils::keyValMap(
                $def->values,
                function ($enumValue) {
                    return $enumValue->name->value;
                },
                function ($enumValue) {
                    return [
                        'description' => $this->getDescription($enumValue),
                        'deprecationReason' => $this->getDeprecationReason($enumValue),
                        'astNode' => $enumValue
                    ];
                }
            )
        ]);
    }

    private function makeUnionDef(UnionTypeDefinitionNode $def)
    {
        return new UnionType([
            'name' => $def->name->value,
            'description' => $this->getDescription($def),
            'types' => Utils::map($def->types, function ($typeNode) {
                return $this->buildObjectType($typeNode);
            }),
            'astNode' => $def
        ]);
    }

    private function makeScalarDef(ScalarTypeDefinitionNode $def)
    {
        return new CustomScalarType([
            'name' => $def->name->value,
            'description' => $this->getDescription($def),
            'astNode' => $def,
            'serialize' => function($value) {
                return $value;
            },
        ]);
    }

    private function makeInputObjectDef(InputObjectTypeDefinitionNode $def)
    {
        return new InputObjectType([
            'name' => $def->name->value,
            'description' => $this->getDescription($def),
            'fields' => function () use ($def) {
                return $this->makeInputValues($def->fields);
            },
            'astNode' => $def,
        ]);
    }

    /**
     * Given a collection of directives, returns the string value for the
     * deprecation reason.
     *
     * @param EnumValueDefinitionNode | FieldDefinitionNode $node
     * @return string
     */
    private function getDeprecationReason($node)
    {
        $deprecated = Values::getDirectiveValues(Directive::deprecatedDirective(), $node);
        return isset($deprecated['reason']) ? $deprecated['reason'] : null;
    }

    /**
     * Given an ast node, returns its string description.
     */
    private function getDescription($node)
    {
        if ($node->description) {
            return $node->description->value;
        }
        if (isset($this->options['commentDescriptions'])) {
            $rawValue = $this->getLeadingCommentBlock($node);
            if ($rawValue !== null) {
                return BlockString::value("\n" . $rawValue);
            }
        }

        return null;
    }

    private function getLeadingCommentBlock($node)
    {
        $loc = $node->loc;
        if (!$loc || !$loc->startToken) {
            return;
        }
        $comments = [];
        $token = $loc->startToken->prev;
        while (
            $token &&
            $token->kind === Token::COMMENT &&
            $token->next && $token->prev &&
            $token->line + 1 === $token->next->line &&
            $token->line !== $token->prev->line
        ) {
            $value = $token->value;
            $comments[] = $value;
            $token = $token->prev;
        }

        return implode("\n", array_reverse($comments));
    }
}
