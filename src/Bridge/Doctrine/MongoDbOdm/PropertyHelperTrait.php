<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\Bridge\Doctrine\MongoDbOdm;

use ApiPlatform\Core\Exception\InvalidArgumentException;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Aggregation\Builder;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata as MongoDbOdmClassMetadata;

/**
 * Helper trait regarding a property in a MongoDB document using the resource metadata.
 *
 * @experimental
 *
 * @author Alan Poulain <contact@alanpoulain.eu>
 */
trait PropertyHelperTrait
{
    /**
     * Splits the given property into parts.
     */
    abstract protected function splitPropertyParts(string $property/*, string $resourceClass*/): array;

    /**
     * Gets class metadata for the given resource.
     */
    abstract protected function getClassMetadata(string $resourceClass): ClassMetadata;

    /**
     * Adds the necessary lookups for a nested property.
     *
     * @throws InvalidArgumentException If property is not nested
     *
     * @return array An array where the first element is the $alias of the lookup,
     *               the second element is the $field name
     *               the third element is the $associations array
     */
    protected function addLookupsForNestedProperty(string $property, Builder $aggregationBuilder, string $resourceClass): array
    {
        $propertyParts = $this->splitPropertyParts($property, $resourceClass);
        $alias = '';

        foreach ($propertyParts['associations'] as $association) {
            $classMetadata = $this->getClassMetadata($resourceClass);

            if (!$classMetadata instanceof MongoDbOdmClassMetadata) {
                break;
            }

            if ($classMetadata->hasReference($association)) {
                $propertyAlias = "${association}_lkup";
                // previous_association_lkup.association
                $localField = "$alias$association";
                // previous_association_lkup.association_lkup
                $alias .= $propertyAlias;
                $referenceMapping = $classMetadata->getFieldMapping($association);

                $aggregationBuilder->lookup($classMetadata->getAssociationTargetClass($association))
                    ->localField($referenceMapping['isOwningSide'] ? $localField : '_id')
                    ->foreignField($referenceMapping['isOwningSide'] ? '_id' : $referenceMapping['mappedBy'])
                    ->alias($alias);
                $aggregationBuilder->unwind("\$$alias");

                // association.property => association_lkup.property
                $property = substr_replace($property, $propertyAlias, strpos($property, $association), \strlen($association));
                $resourceClass = $classMetadata->getAssociationTargetClass($association);
                $alias .= '.';
            } elseif ($classMetadata->hasEmbed($association)) {
                $alias = "$association.";
                $resourceClass = $classMetadata->getAssociationTargetClass($association);
            }
        }

        if ('' === $alias) {
            throw new InvalidArgumentException(sprintf('Cannot add lookups for property "%s" - property is not nested.', $property));
        }

        return [$property, $propertyParts['field'], $propertyParts['associations']];
    }
}
