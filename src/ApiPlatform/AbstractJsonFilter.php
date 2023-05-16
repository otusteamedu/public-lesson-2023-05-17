<?php

namespace App\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractContextAwareFilter;
use ApiPlatform\Core\Exception\InvalidArgumentException;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;

abstract class AbstractJsonFilter extends AbstractContextAwareFilter
{
    protected const DOCTRINE_JSON_TYPES = [
        Types::JSON => true,
    ];

    protected const TRUE_VALUE = 'true';
    protected const FALSE_VALUE = 'false';

    protected function getBooleanValue(array $values, string $property): ?bool
    {
        if (in_array($values[0], [true, self::TRUE_VALUE, '1'], true)) {
            return true;
        }

        if (in_array($values[0], [false, self::FALSE_VALUE, '0'], true)) {
            return false;
        }

        $incites = implode('" | "', [self::TRUE_VALUE, self::FALSE_VALUE, '1', '0']);

        $this->logNotice(
            'Invalid filter ignored',
            'Invalid boolean value for "%s" property, expected one of ( "%s" )',
            [$property, $incites],
        );

        return null;
    }

    protected function isJsonField(string $property, string $resourceClass): bool
    {
        return isset(self::DOCTRINE_JSON_TYPES[(string) $this->getDoctrineFieldType($property, $resourceClass)]);
    }

    protected function logNotice(string $type, string $message, array $properties): void
    {
        $implodedProperties = implode(',', $properties);
        $exception = new InvalidArgumentException(sprintf($message, $implodedProperties));
        $this->getLogger()->notice($exception->getMessage(), [
            'exception' => $exception,
            'type' => $type,
        ]);
    }

    protected function getJsonColumn(string $property): string
    {
        $jsonColumnInfos = explode('.', $property);

        return reset($jsonColumnInfos);
    }

    protected function getJsonKey(string $property): string
    {
        $jsonColumnInfos = explode('.', $property);
        array_shift($jsonColumnInfos);

        return implode(',', $jsonColumnInfos);
    }

    /**
     * @throws Exception
     */
    protected function validateDatabasePlatform(QueryBuilder $queryBuilder): void
    {
        $databasePlatform = $queryBuilder
            ->getEntityManager()
            ->getConnection()
            ->getDatabasePlatform();
        if (!($databasePlatform instanceof PostgreSQLPlatform)) {
            $message = sprintf(
                'Invalid database platform: postgresql required but %s found',
                $databasePlatform::class,
            );
            throw new Exception($message);
        }
    }
}
