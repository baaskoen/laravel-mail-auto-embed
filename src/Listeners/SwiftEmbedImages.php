<?php

namespace Eduardokum\LaravelMailAutoEmbed\Listeners;

use Eduardokum\LaravelMailAutoEmbed\Embedder\AttachmentEmbedder;
use Eduardokum\LaravelMailAutoEmbed\Embedder\Base64Embedder;
use Eduardokum\LaravelMailAutoEmbed\Embedder\Embedder;
use Eduardokum\LaravelMailAutoEmbed\Models\EmbeddableEntity;
use ReflectionClass;
use Swift_Events_SendEvent;
use Swift_Events_SendListener;
use Swift_Message;

class SwiftEmbedImages implements Swift_Events_SendListener
{
    /**
     * @var  array
     */
    private $config;

    /**
     * @var  Swift_Message
     */
    private $message;

    /**
     * @param  array  $config
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * @param  Swift_Events_SendEvent  $evt
     */
    public function beforeSendPerformed(Swift_Events_SendEvent $evt)
    {
        $this->message = $evt->getMessage();

        $this->attachImages();
    }

    /**
     * @param  Swift_Events_SendEvent  $evt
     * @return bool
     */
    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
        return true;
    }

    /**
     *
     */
    private function attachImages()
    {
        $html_body = $this->message->getBody();

        $html_body = preg_replace_callback('/<img.*src="(.*?)"\s?(.*)?>/', [$this, 'replaceCallback'], $html_body);

        $this->message->setBody($html_body);
    }

    /**
     * @param  array  $match
     * @return string
     */
    private function replaceCallback($match)
    {
        $imageTag   = $match[0];
        $src        = $match[1];
        $attributes = $match[2];

        return $this->needsEmbed($imageTag)
            ? '<img src="'.$this->embed($src).'" '.$attributes.'/>'
            : $imageTag;
    }

    /**
     * @param  string  $imageTag
     * @return bool
     */
    private function needsEmbed($imageTag)
    {
        // Don't embed if 'data-skip-embed' is present
        if (strpos($imageTag, 'data-skip-embed') !== false) {
            return false;
        }

        // Don't embed if auto-embed is disabled and 'data-auto-embed' is absent
        if (!$this->config['enabled'] && strpos($imageTag, 'data-auto-embed') === false) {
            return false;
        }

        return true;
    }

    /**
     * @return Embedder
     */
    private function getEmbedder()
    {
        switch ($this->config['method']) {

            case 'attachment':
                return new AttachmentEmbedder($this->message);

            case 'base64':
                return new Base64Embedder();

            default:
                throw new \InvalidArgumentException(sprintf('Invalid embed method %s', $this->config['method']));
        }
    }

    /**
     * @param  string  $src
     * @return string
     */
    private function embed($src)
    {
        // Entity embedding
        if (strpos($src, 'embed:') === 0) {

            $embedParams = explode(':', $src);
            if (count($embedParams) < 3) {
                return $src;
            }

            $className = urldecode($embedParams[1]);
            $id = $embedParams[2];

            if (!class_exists($className)) {
                return $src;
            }

            $class = new ReflectionClass($className);
            if (! $class->implementsInterface(EmbeddableEntity::class) ) {
                return $src;
            }

            /** @var EmbeddableEntity $className */
            if (! $instance = $className::findEmbeddable($id)) {
                return $src;
            }

            return $this->getEmbedder()->fromEntity($instance);
        }

        // URL embedding
        if (filter_var($src, FILTER_VALIDATE_URL) !== false) {
            return $this->getEmbedder()->fromUrl($src);
        }

        return $src;
    }
}
