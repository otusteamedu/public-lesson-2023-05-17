<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\RangeFilter;
use App\ApiPlatform\JsonFilter;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Table(name: 'subscription')]
#[ORM\Index(columns: ['author_id'], name: 'subscription__author_id__ind')]
#[ORM\Index(columns: ['follower_id'], name: 'subscription__follower_id__ind')]
#[ORM\UniqueConstraint(name: 'subscription__author_id__follower_id__uniq', columns: ['author_id', 'follower_id'])]
#[ORM\Entity]
#[ApiResource(normalizationContext: ['groups' => ['subscription:get']])]
#[ApiFilter(RangeFilter::class, properties: ['author.id'])]
#[ApiFilter(JsonFilter::class, properties: ['follower.config.type' => ['type' => 'string', 'strategy' => 'partial']])]
class Subscription
{
    #[ORM\Column(name: 'id', type: 'bigint', unique: true)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: 'User', inversedBy: 'followers')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id')]
    #[Groups(['subscription:get'])]
    private User $author;

    #[ORM\ManyToOne(targetEntity: 'User', inversedBy: 'authors')]
    #[ORM\JoinColumn(name: 'follower_id', referencedColumnName: 'id')]
    #[Groups(['subscription:get'])]
    private User $follower;

    #[ORM\Column(name: 'created_at', type: 'datetime', nullable: false)]
    private DateTime $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime', nullable: false)]
    private DateTime $updatedAt;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function setAuthor(User $author): void
    {
        $this->author = $author;
    }

    public function getFollower(): User
    {
        return $this->follower;
    }

    public function setFollower(User $follower): void
    {
        $this->follower = $follower;
    }

    public function getCreatedAt(): DateTime {
        return $this->createdAt;
    }

    public function setCreatedAt(): void {
        $this->createdAt = new DateTime();
    }

    public function getUpdatedAt(): DateTime {
        return $this->updatedAt;
    }

    public function setUpdatedAt(): void {
        $this->updatedAt = new DateTime();
    }
}
