<?php
namespace App\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use App\Common\Exception\InvalidDatabasePlatformException;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\DBAL\Types\Types as DBALType;

/**
 * ATTENTION!!! Filter will only work for PostgreSQL
 */
class JsonFilter extends AbstractJsonFilter
{
    public const TYPE_STRING = 'string';
    public const TYPE_INT = 'int';
    public const TYPE_FLOAT = 'float';
    public const TYPE_BOOLEAN = 'bool';

    public const AVAILABLE_TYPES = [
        self::TYPE_STRING => true,
        self::TYPE_INT => true,
        self::TYPE_FLOAT => true,
        self::TYPE_BOOLEAN => true,
    ];

    public const STRATEGY_EXACT = 'exact';
    public const STRATEGY_PARTIAL = 'partial';
    public const STRATEGY_START = 'start';
    public const STRATEGY_END = 'end';
    public const STRATEGY_WORD_START = 'word_start';
    public const STRATEGY_CASE_INSENSITIVE_WORD_START = 'iword_start';
    public const STRATEGY_CASE_INSENSITIVE_EXACT = 'iexact';
    public const STRATEGY_CASE_INSENSITIVE_PARTIAL = 'ipartial';
    public const STRATEGY_CASE_INSENSITIVE_START = 'istart';
    public const STRATEGY_CASE_INSENSITIVE_END = 'iend';

    public const STRATEGY_MAPPING = [
        self::STRATEGY_EXACT => self::STRATEGY_EXACT,
        self::STRATEGY_PARTIAL => self::STRATEGY_PARTIAL,
        self::STRATEGY_START => self::STRATEGY_START,
        self::STRATEGY_END => self::STRATEGY_END,
        self::STRATEGY_WORD_START => self::STRATEGY_WORD_START,
        self::STRATEGY_CASE_INSENSITIVE_EXACT => self::STRATEGY_EXACT,
        self::STRATEGY_CASE_INSENSITIVE_PARTIAL => self::STRATEGY_PARTIAL,
        self::STRATEGY_CASE_INSENSITIVE_START => self::STRATEGY_START,
        self::STRATEGY_CASE_INSENSITIVE_END => self::STRATEGY_END,
        self::STRATEGY_CASE_INSENSITIVE_WORD_START => self::STRATEGY_WORD_START,
    ];
    public const STRATEGY_CASE_SENSITIVITY_MAPPING = [
        self::STRATEGY_EXACT => true,
        self::STRATEGY_PARTIAL => true,
        self::STRATEGY_START => true,
        self::STRATEGY_END => true,
        self::STRATEGY_WORD_START => true,
        self::STRATEGY_CASE_INSENSITIVE_EXACT => false,
        self::STRATEGY_CASE_INSENSITIVE_PARTIAL => false,
        self::STRATEGY_CASE_INSENSITIVE_START => false,
        self::STRATEGY_CASE_INSENSITIVE_END => false,
        self::STRATEGY_CASE_INSENSITIVE_WORD_START => false,
    ];

    private const DEFAULT_TYPE = self::TYPE_STRING;
    private const DEFAULT_STRATEGY = self::STRATEGY_EXACT;

    /**
     * {@inheritdoc}
     */
    public function getDescription(string $resourceClass): array
    {
        if (!$this->properties) {
            return [];
        }

        $description = [];

        foreach ($this->properties as $property => $config) {
            $propertyName = $property;
            $propertyType = $this->getPropertyType($property);
            $propertyStrategy = $this->getPropertyStrategy($property);
            $isPropertyRequired = $this->isPropertyRequired($config);
            $filterParameterNames = [$propertyName];
            $filterParameterNames = $this->addPropertyNameToFilter(
                $filterParameterNames,
                $propertyType,
                $propertyName,
                $propertyStrategy,
            );

            foreach ($filterParameterNames as $filterParameterName) {
                $description[$filterParameterName] = [
                    'property' => $propertyName,
                    'type' => $propertyType,
                    'strategy' => $propertyStrategy,
                    'required' => $isPropertyRequired,
                    'is_collection' => str_ends_with($filterParameterName, '[]'),
                ];
            }
        }

        return $description;
    }

    /**
     * @param mixed $value
     *
     * @throws InvalidDatabasePlatformException|Exception
     */
    protected function filterProperty(
        string $property,
               $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?string $operationName = null,
    ): void {
        $this->validateDatabasePlatform($queryBuilder);
        $jsonColumn = $this->getJsonColumn($property);

        if (
            $value === null ||
            !$this->isPropertyEnabled($property, $resourceClass) ||
            !$this->isPropertyMapped($jsonColumn, $resourceClass) ||
            !$this->isJsonField($jsonColumn, $resourceClass) ||
            !$this->isValidType($property) ||
            !$this->isValidStrategy($property)
        ) {
            return;
        }

        $values = $this->normalizeValues((array) $value, $property);
        $type = $this->getPropertyType($property);

        match ($type) {
            self::TYPE_STRING => $this->addStringFilter($property, $values, $queryBuilder, $queryNameGenerator),
            self::TYPE_INT, self::TYPE_FLOAT => $this->addNumericFilter(
                $property,
                $values,
                $queryBuilder,
                $queryNameGenerator,
            ),
            self::TYPE_BOOLEAN => $this->addBooleanFilter(
                $property,
                (bool) $values,
                $queryBuilder,
                $queryNameGenerator,
            ),
            default => throw new InvalidArgumentException(
                sprintf('Type "%s" specified for property "%s" is not supported', $type, $property),
            ),
        };
    }

    private function getPropertyType(string $property): string
    {
        $properties = $this->getProperties() ?? [];
        if (!array_key_exists($property, $properties)) {
            return self::DEFAULT_TYPE;
        }
        /** @var array<string,string> $propertyConfig */
        $propertyConfig = $properties[$property];

        return $propertyConfig['type'] ?? self::DEFAULT_TYPE;
    }

    private function isPropertyRequired($config): bool
    {
        return isset($config['required']) && filter_var($config['required'], FILTER_VALIDATE_BOOLEAN);
    }

    private function isValidType(string $property): bool
    {
        $propertyType = $this->getPropertyType($property);

        if (isset(self::AVAILABLE_TYPES[$propertyType])) {
            return true;
        }

        $this->logNotice('Invalid filter type', 'Invalid filter type (%s) specified for "%s" property', [
            $propertyType,
            $property,
        ]);

        return false;
    }

    private function isValidStrategy(string $property): bool
    {
        $strategy = $this->getPropertyStrategy($property);

        if (isset(self::STRATEGY_MAPPING[$strategy])) {
            return true;
        }

        $this->logNotice('Invalid filter strategy', 'Invalid filter strategy (%s) specified for "%s" property', [
            $strategy,
            $property,
        ]);

        return false;
    }

    private function normalizeValues(array $values, string $property): array|bool|null
    {
        if (count($values) === 0) {
            $this->logNotice(
                'Invalid filter ignored',
                'At least one value is required, multiple values should be in "%1$s[]=firstvalue&%1$s[]=secondvalue" format',
                [$property],
            );
        }

        $propertyType = $this->getPropertyType($property);
        return match ($propertyType) {
            self::TYPE_INT, self::TYPE_FLOAT => $this->isNumericValue($values, $property) ? array_values($values) : [],
            self::TYPE_BOOLEAN => $this->getBooleanValue($values, $property),
            default => array_values($values),
        };
    }

    private function isNumericValue(array $values, string $property): bool
    {
        if (!is_numeric($values[0]) && !$this->isNumericArray($values)) {
            $this->logNotice('Invalid filter ignored', 'Invalid numeric value for "%s" property', [$property]);

            return false;
        }

        return true;
    }

    private function isNumericArray(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_numeric($value)) {
                return false;
            }
        }

        return true;
    }

    private function getPropertyStrategy(string $property): string
    {
        $properties = $this->getProperties() ?? [];
        if (!array_key_exists($property, $properties)) {
            return self::DEFAULT_STRATEGY;
        }
        /** @var array<string,string> $propertyConfig */
        $propertyConfig = $properties[$property];

        return $propertyConfig['strategy'] ?? self::DEFAULT_STRATEGY;
    }

    /**
     * @param string[] $filterParameterNames
     *
     * @return string[]
     */
    private function addPropertyNameToFilter(
        array $filterParameterNames,
        string $propertyType,
        string $propertyName,
        string $propertyStrategy,
    ): array {
        if (
            $propertyType === self::TYPE_INT ||
            $propertyType === self::TYPE_FLOAT ||
            ($propertyType === self::TYPE_STRING && $propertyStrategy === self::STRATEGY_EXACT)
        ) {
            $filterParameterNames[] = $propertyName . '[]';
        }

        return $filterParameterNames;
    }

    /**
     * @param array<int,float> $values
     */
    private function addNumericFilter(
        string $property,
        array $values,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
    ): void {
        $alias = $queryBuilder->getRootAliases()[0];
        $jsonColumn = $this->getJsonColumn($property);
        $jsonKey = $this->getJsonKey($property);
        $propertyType = $this->getPropertyType($property);

        if (count($values) === 1) {
            $valueParameter = $queryNameGenerator->generateParameterName($jsonColumn);

            $queryBuilder
                ->andWhere(
                    $this->getWhereExpressionForJsonKey(
                        $alias,
                        $jsonColumn,
                        $jsonKey,
                        true,
                        ":{$valueParameter}",
                        true,
                    ),
                )
                ->setParameter(
                    $valueParameter,
                    $values[0],
                    $propertyType === self::TYPE_FLOAT ? DBALType::FLOAT : DBALType::INTEGER,
                );
        } else {
            $valueParameterPrefix = $queryNameGenerator->generateParameterName($jsonColumn);
            $index = 0;
            $conditions = [];
            $parameters = [];
            foreach ($values as $value) {
                $fullValueParameterName = sprintf('%s_%s', $valueParameterPrefix, $index);
                $conditions[] = $this->getWhereExpressionForJsonKey(
                    $alias,
                    $jsonColumn,
                    $jsonKey,
                    true,
                    ":{$fullValueParameterName}",
                    true,
                );
                $parameters[$fullValueParameterName] = (string) $value;
                $index++;
            }

            $queryBuilder->andWhere(sprintf('(%s)', implode(' OR ', $conditions)));

            foreach ($parameters as $key => $value) {
                $queryBuilder->setParameter($key, $value);
            }
        }
    }

    private function addBooleanFilter(
        string $property,
        ?bool $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
    ): void {
        $alias = $queryBuilder->getRootAliases()[0];
        $jsonColumn = $this->getJsonColumn($property);
        $jsonKey = $this->getJsonKey($property);
        $valueParameter = $queryNameGenerator->generateParameterName($jsonColumn);

        $condition = $this->getWhereExpressionForJsonKey(
            $alias,
            $jsonColumn,
            $jsonKey,
            true,
            ":{$valueParameter}",
            true,
        );

        $queryBuilder
            ->andWhere(sprintf('(%s)', $condition))
            ->setParameter($valueParameter, $value ? self::TRUE_VALUE : self::FALSE_VALUE);
    }

    /**
     * @param string[] $values
     */
    private function addStringFilter(
        string $property,
        array $values,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
    ): void {
        $strategy = $this->getPropertyStrategy($property);
        $caseSensitive = self::STRATEGY_CASE_SENSITIVITY_MAPPING[$strategy];
        $strategy = self::STRATEGY_MAPPING[$strategy];
        $alias = $queryBuilder->getRootAliases()[0];
        $jsonColumn = $this->getJsonColumn($property);
        $jsonKey = $this->getJsonKey($property);

        if (count($values) === 1) {
            $this->addStringFilterWhereByStrategy(
                $strategy,
                $queryBuilder,
                $queryNameGenerator,
                $alias,
                $jsonColumn,
                $jsonKey,
                $values[0],
                $caseSensitive,
            );

            return;
        }

        if ($strategy !== self::STRATEGY_EXACT) {
            $this->logNotice(
                'Invalid filter ignored',
                '"%s" strategy selected for "%s" property, but only "%s" strategy supports multiple values',
                [$strategy, $property],
            );

            return;
        }

        $condition = '';

        // forge condition
        foreach ($values as $index => $value) {
            if ($index > 0) {
                $condition .= ' OR ';
            }
            $valueParameter = $queryNameGenerator->generateParameterName($jsonColumn . $index);
            $condition .= $this->getWhereExpressionForJsonKey(
                $alias,
                $jsonColumn,
                $jsonKey,
                true,
                ":{$valueParameter}",
                $caseSensitive,
            );
            $value = $caseSensitive ? $value : strtolower($value);
            $queryBuilder->setParameter($valueParameter, $value, Types::STRING);
        }

        $queryBuilder->andWhere(sprintf('(%s)', $condition));
    }

    private function addStringFilterWhereByStrategy(
        string $strategy,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $alias,
        string $jsonColumn,
        string $jsonKey,
        string $value,
        bool $caseSensitive,
    ): void {
        $valueParameter = $queryNameGenerator->generateParameterName($jsonColumn);
        $value = $caseSensitive ? $value : strtolower($value);

        switch ($strategy) {
            case null:
            case self::STRATEGY_EXACT:
                $queryBuilder
                    ->andWhere(
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            true,
                            ":{$valueParameter}",
                            $caseSensitive,
                        ),
                    )
                    ->setParameter($valueParameter, $value, Types::STRING);
                break;
            case self::STRATEGY_PARTIAL:
                $queryBuilder
                    ->andWhere(
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            false,
                            "CONCAT('%%', :" . $valueParameter . "'%%')",
                            $caseSensitive,
                        ),
                    )
                    ->setParameter($valueParameter, $value, Types::STRING);

                break;
            case self::STRATEGY_START:
                $queryBuilder
                    ->andWhere(
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            false,
                            'CONCAT(:' . $valueParameter . "'%%')",
                            $caseSensitive,
                        ),
                    )
                    ->setParameter($valueParameter, $value, Types::STRING);
                break;
            case self::STRATEGY_END:
                $queryBuilder
                    ->andWhere(
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            false,
                            "CONCAT('%%', :" . $valueParameter . ')',
                            $caseSensitive,
                        ),
                    )
                    ->setParameter($valueParameter, $value, Types::STRING);
                break;
            case self::STRATEGY_WORD_START:
                $queryBuilder
                    ->andWhere(
                        '(' .
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            false,
                            'CONCAT(:' . $valueParameter . "'%%')",
                            $caseSensitive,
                        ) .
                        ' OR ' .
                        $this->getWhereExpressionForJsonKey(
                            $alias,
                            $jsonColumn,
                            $jsonKey,
                            false,
                            "CONCAT('%% ', :" . $valueParameter . "'%%')",
                            $caseSensitive,
                        ) .
                        ')',
                    )
                    ->setParameter($valueParameter, $value, Types::STRING);
                break;
            default:
                throw new InvalidArgumentException("Strategy {$strategy} does not exist.");
        }
    }

    private function getWhereExpressionForJsonKey(
        string $alias,
        string $jsonColumn,
        string $jsonKey,
        bool $strictComparison,
        string $valueParameter,
        bool $caseSensitive,
    ): string {
        $operator = $strictComparison ? '=' : 'LIKE';
        return $caseSensitive
            ? sprintf("JSON_GET_TEXT(%s.%s, '%s') %s %s", $alias, $jsonColumn, $jsonKey, $operator, $valueParameter)
            : sprintf(
                "LOWER(JSON_GET_TEXT(%s.%s, '%s')) %s %s",
                $alias,
                $jsonColumn,
                $jsonKey,
                $operator,
                $valueParameter,
            );
    }
}
