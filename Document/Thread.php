<?php

namespace Ornicar\MessageBundle\Document;

use Ornicar\MessageBundle\Model\Thread as AbstractThread;
use Ornicar\MessageBundle\Model\MessageInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\ArrayCollection;
use Ornicar\MessageBundle\Model\ParticipantInterface;

abstract class Thread extends AbstractThread
{
    /**
     * Messages contained in this thread
     *
     * @var Collection of MessageInterface
     */
    protected $messages;

    /**
     * Thread metadata
     *
     * @var Collection of ThreadMetadata
     */
    protected $metadata;

    /**
     * Users participating in this conversation
     *
     * @var Collection of ParticipantInterface
     */
    protected $participants;

    /**
     * Participant that created the thread
     *
     * @var ParticipantInterface
     */
    protected $createdBy;

    /**
     * Date this thread was created at
     *
     * @var \DateTime
     */
    protected $createdAt;

    /**
     * All text contained in the thread messages
     * Used for the full text search
     *
     * @var string
     */
    protected $keywords = '';

    /**
     * Initializes the collections
     */
    public function __construct()
    {
        $this->messages = new ArrayCollection();
        $this->metadata = new ArrayCollection();
        $this->participants = new ArrayCollection();
    }

    /**
     * Gets the messages contained in the thread
     *
     * @return array of MessageInterface
     */
    public function getMessages()
    {
        return $this->messages->toArray();
    }

    /**
     * Adds a new message to the thread
     *
     * @param MessageInterface $message
     */
    public function addMessage(MessageInterface $message)
    {
        $this->messages->add($message);
    }

    /**
     * Gets the participant that created the thread
     * Generally the sender of the first message
     *
     * @return ParticipantInterface
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Sets the participant that created the thread
     * Generally the sender of the first message
     *
     * @param ParticipantInterface
     */
    public function setCreatedBy(ParticipantInterface $participant)
    {
        $this->createdBy = $participant;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * @param  \DateTime
     * @return null
     */
    public function setCreatedAt(\DateTime $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Gets the users participating in this conversation
     *
     * @return array of ParticipantInterface
     */
    public function getParticipants()
    {
        return $this->participants->toArray();
    }

    /**
     * Adds a participant to the thread
     * If it already exists, nothing is done.
     *
     * @param ParticipantInterface $participant
     * @return null
     */
    public function addParticipant(ParticipantInterface $participant)
    {
        if (!$this->isParticipant($participant)) {
            $this->participants->add($participant);
        }
    }

    /**
     * Tells if the user participates to the conversation
     *
     * @param ParticipantInterface $participant
     * @return boolean
     */
    public function isParticipant(ParticipantInterface $participant)
    {
        return $this->participants->contains($participant);
    }

    /**
     * Tells if this thread is deleted by this participant
     *
     * @return bool
     */
    public function isDeletedByParticipant(ParticipantInterface $participant)
    {
        if ($meta = $this->getMetadataForParticipant($participant)) {
            return $meta->getIsDeleted();
        }

        return false;
    }

    /**
     * Sets whether or not this participant has deleted this thread
     *
     * @param ParticipantInterface $participant
     * @param boolean $isDeleted
     * @throws InvalidArgumentException if no metadata exists for the participant
     */
    public function setIsDeletedByParticipant(ParticipantInterface $participant, $isDeleted)
    {
        if (!$meta = $this->getMetadataForParticipant($participant)) {
            throw new \InvalidArgumentException(sprintf('No metadata exists for participant with id "%s"', $participant->getId()));
        }

        $meta->setIsDeleted($isDeleted);

        if ($isDeleted) {
            // also mark all thread messages as read
            foreach ($this->getMessages() as $message) {
                $message->setIsReadByParticipant($participant, true);
            }
        }
    }

    /**
     * @param ParticipantInterface $participant
     * @return ThreadMetadata
     */
    public function getMetadataForParticipant(ParticipantInterface $participant)
    {
        foreach ($this->metadata as $meta) {
            if ($meta->getParticipant()->getId() == $participant->getId()) {
                return $meta;
            }
        }

        return null;
    }

    /**
     * @param ThreadMetadata $meta
     */
    public function addMetadata(ThreadMetadata $meta)
    {
        $this->metadata->add($meta);
    }

    /**
     * DENORMALIZATION
     *
     * All following methods are relative to denormalization
     */

    /**
     * Performs denormalization tricks
     */
    public function denormalize()
    {
        $this->doCreatedByAndAt();
        $this->doKeywords();
        $this->doSpam();
        $this->doMetadataLastMessageDates();
    }

    /**
     * Ensures that the createdBy & createdAt properties are set
     */
    protected function doCreatedByAndAt()
    {
        if (null !== $this->getCreatedBy()) {
            return;
        }

        if (!$message = $this->getFirstMessage()) {
            return;
        }

        $this->setCreatedBy($message->getSender());
        $this->setCreatedAt($message->getCreatedAt());
    }

    /**
     * Adds all messages contents to the keywords property
     */
    protected function doKeywords()
    {
        $keywords = $this->getSubject();

        foreach ($this->getMessages() as $message) {
            $keywords .= ' '.$message->getBody();
        }

        // we only need each word once
        $this->keywords = implode(' ', array_unique(str_word_count(mb_strtolower($keywords, 'UTF-8'), 1)));
    }

    /**
     * Denormalizes the value of isSpam to messages
     */
    protected function doSpam()
    {
        foreach ($this->getMessages() as $message) {
            $message->setIsSpam($this->getIsSpam());
        }
    }

    /**
     * Ensures that metadata last message dates are up to date
     *
     * Precondition: metadata exists for all thread participants
     */
    protected function doMetadataLastMessageDates()
    {
        foreach ($this->metadata as $meta) {
            foreach ($this->getMessages() as $message) {
                if ($meta->getParticipant()->getId() !== $message->getSender()->getId()) {
                    if (null === $meta->getLastMessageDate() || $meta->getLastMessageDate()->getTimestamp() < $message->getTimestamp()) {
                        $meta->setLastMessageDate($message->getCreatedAt());
                    }
                } else {
                    if (null === $meta->getLastParticipantMessageDate() || $meta->getLastParticipantMessageDate()->getTimestamp() < $message->getTimestamp()) {
                        $meta->setLastParticipantMessageDate($message->getCreatedAt());
                    }
                }
            }
        }
    }
}
