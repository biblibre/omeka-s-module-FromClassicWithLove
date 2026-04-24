<?php
namespace FromClassicWithLove\Entity;

use Omeka\Entity\AbstractEntity;

/**
 * @Entity
 */
class FromClassicWithLoveResourceMap extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @ManyToOne(targetEntity="Omeka\Entity\Job")
     * @JoinColumn(nullable=false)
     */
    protected $job;

    /**
     * @ManyToOne(
     *     targetEntity="Omeka\Entity\Resource",
     *     inversedBy=null
     * )
     * @JoinColumn(
     *     onDelete="CASCADE",
     *     nullable=false
     * )
     */
    protected $resource;

    /**
     * @Column(type="integer")
     */
    protected $classicResourceId;

    /**
     * @Column(type="string")
     */
    protected $mappedResourceName;

    public function getResourceName()
    {
        return 'classicimporter_resource_maps';
    }

    public function getId()
    {
        return $this->id;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setResource($resource)
    {
        $this->resource = $resource;
    }

    public function getClassicResourceId()
    {
        return $this->classicResourceId;
    }

    public function setClassicResourceId($classicResourceId)
    {
        $this->classicResourceId = $classicResourceId;
    }

    public function getMappedResourceName()
    {
        return $this->mappedResourceName;
    }

    public function setMappedResourceName($mappedResourceName)
    {
        $this->mappedResourceName = $mappedResourceName;
    }

    public function setJob(\Omeka\Entity\Job $job)
    {
        $this->job = $job;
    }

    public function getJob()
    {
        return $this->job;
    }
}
