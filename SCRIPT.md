# Фильтры в API Platform

## Инициализация

1. Запускаем docker-контейнеры командой `docker-compose up -d`
2. Логинимся в контейнер `php` командой `docker exec -it php sh`
3. Устанавливаем зависимости командой `composer install`
4. Выполняем миграции командой `php bin/console doctrine:migrations:migrate`
5. Заполняем базу данных запросами
   ```sql
   DELIMITER //
   Create or replace function random_string(length integer) returns text as
   $$
   declare
     chars text[] := '{0,1,2,3,4,5,6,7,8,9,A,B,C,D,E,F,G,H,I,J,K,L,M,N,O,P,Q,R,S,T,U,V,W,X,Y,Z,a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p,q,r,s,t,u,v,w,x,y,z}';
     result text := '';
     i integer := 0;
   begin
     if length < 0 then
       raise exception 'Given length cannot be less than 0';
     end if;
     for i in 1..length loop
       result := result || chars[1+random()*(array_length(chars, 1)-1)];
     end loop;
     return result;
   end;
   $$ language plpgsql;
   
   insert INTO "user"("id","login","age","config","created_at","updated_at")
     select 
      gs.id,
       random_string((1 + random()*29)::integer), 
       (18 + RANDOM()*50)::INTEGER,
      ('{"type":"' || random_string((1 + RANDOM()*29)::INTEGER) || '"}')::json,
      CURRENT_TIMESTAMP,
      current_timestamp
     from generate_series(1,10000) as gs(id);

   insert INTO "subscription"("id","author_id","follower_id","created_at","updated_at")
     select
       gs.id,
       (RANDOM()*5000)::INTEGER,
       (5000 + RANDOM()*5000)::INTEGER,
       CURRENT_TIMESTAMP,
       current_timestamp
   from generate_series(1,1000) as gs(id);
   ```

## Устанавливаем API Platform

1. Устанавливаем необходимые пакеты командой `composer require api`
2. Добавляем атрибут `ApiResource` к классам `App\Entity\User` и `App\Entity\Subscription`
3. Заходим по адресу `http://localhost:7777/api`, видим наши ресурсы

### Фильтруем по текстовым полям

1. К классу `App\Entity\User` добавим атрибут для фильтрации
    ```php
    #[ApiFilter(SearchFilter::class, properties: ['login' => 'partial'])]
    ```
2. Заходим по адресу `http://localhost:7777/api`, видим в запросе `GET /api/users` новое поле `login`
3. Пробуем поиск по части названия, видим результат
4. В классе `App\Entity\User` меняем атрибут на
    ```php
    #[ApiFilter(SearchFilter::class, properties: ['login' => 'exact'])]
    ```
5. Пробуем поиск по части названия, результат пустой
6. Пробуем поиск по полному названию, видим результат

### Пробуем применить к числовым полям

1. В классе `App\Entity\User` меняем атрибут на
    ```php
    #[ApiFilter(SearchFilter::class, properties: ['age' => 'exact'])]
    ```
2. Пробуем поиск, находятся значения с точным совпадением

### Поиск по диапазону

1. В классе `App\Entity\User` меняем атрибут на
    ```php
    #[ApiFilter(RangeFilter::class, properties: ['age'])]
    ```
2. Пробуем поиск с разными вариантами границ
3. Пробуем поиск по диапазону (32..34)

### Поиск по вложенным ресурсам

1. К классу `App\Entity\Subscription` добавим атрибут для фильтрации
    ```php
    #[ApiFilter(RangeFilter::class, properties: ['author.id'])]
    ```
2. Пробуем в запросе `GET /api/subscriptions` поиск по автору, видим только ссылки
3. В классе `App\Entity\Subscription`
   1. Исправляем атрибут `ApiResource`
       ```php
       #[ApiResource(normalizationContext: ['groups' => ['subscription:get']])]
       ```
   2. Добавляем к полям `author` и `follower` атрибут
       ```php
       #[Groups(['subscription:get'])] 
       ```
4. В классе `App\Entity\User` добавляем к полям `login`, `age` и `config` атрибут
    ```php
    #[Groups(['subscription:get'])]
    ```
5. Ещё раз пробуем запрос, видим для автора и подписчика логин, возраст и конфиг

### Добавляем кастомный фильтр

1. Устанавливаем пакет `scienta/doctrine-json-functions`
2. В файле `config\packages\doctrine` добавляем в секцию `doctrine.orm` новую секцию
    ```yaml
    dql:
        string_functions:
            JSON_GET_TEXT: Scienta\DoctrineJsonFunctions\Query\AST\Functions\Postgresql\JsonGetText
    ```
3. Добавляем класс `App\ApiPlatform\AbstractJsonFilter`
    ```php
    <?php
    
    namespace App\ApiPlatform;
    
    use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractContextAwareFilter;
    use ApiPlatform\Core\Exception\InvalidArgumentException;
    use Doctrine\DBAL\Exception;
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
    ```
4. Добавляем класс `App\ApiPlatform\JsonFilter`
    ```php
    <?php
    namespace App\ApiPlatform;
    
    use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
    use ApiPlatform\Core\Exception\InvalidArgumentException;
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
         * @throws Exception
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
    ```
5. К классу `App\Entity\User` добавляем атрибут
    ```php
    #[ApiFilter(JsonFilter::class, properties: ['config.type' => ['type' => 'string', 'strategy' => 'exact']])
    ```
6. Выполняем запрос `GET /api/users` с фильтром по полному значению поля `config.type`, видим результат
7. В классе `App\Entity\User` изменяем атрибут
    ```php
    #[ApiFilter(JsonFilter::class, properties: ['config.type' => ['type' => 'string', 'strategy' => 'iend']])
    ```
8. Выполняем запрос `GET /api/users` с фильтром по суффиксу слова с нарушением регистра, видим результат

### Пробуем фильтровать по вложенному ресурсу

1. К классу `App\Entity\Subscription` добавляем атрибут
    ```php
    #[ApiFilter(JsonFilter::class, properties: ['follower.config.type' => ['type' => 'string', 'strategy' => 'partial']])
    ```
2. Выполняем запрос `GET /api/subscriptions` с фильтром по подстроке конфига, видим, что фильтр не отрабатывает

