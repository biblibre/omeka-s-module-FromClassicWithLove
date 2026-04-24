<?php
namespace FromClassicWithLove\Entity;

use Omeka\Entity\AbstractEntity;
use Omeka\Entity\Job;

/**
 * @Entity
 */
class FromClassicWithLoveImport extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @OneToOne(targetEntity="Omeka\Entity\Job")
     * @JoinColumn(nullable=false)
     */
    protected $job;

    /**
     * @OneToOne(targetEntity="Omeka\Entity\Job")
     * @JoinColumn(nullable=true)
     */
    protected $undoJob;

    /**
     * @Column(type="boolean")
     */
    protected $has_err;

    /**
     * @Column(type="json_array")
     */
    protected $stats;

    public function getId()
    {
        return $this->id;
    }

    public function setJob(Job $job)
    {
        $this->job = $job;
    }

    public function getJob()
    {
        return $this->job;
    }

    public function setUndoJob(Job $job)
    {
        $this->undoJob = $job;
    }

    public function getUndoJob()
    {
        return $this->undoJob;
    }

    public function setHasErr($hasErr)
    {
        $this->has_err = $hasErr;
    }

    public function getHasErr()
    {
        return $this->has_err;
    }

    public function setStats($stats)
    {
        $this->stats = $stats;
    }

    public function getStats()
    {
        return $this->stats;
    }
}
