<?php

/**
 * @file
 * Contains \Drupal\feeds\Form\FeedDeleteForm.
 */

namespace Drupal\feeds\Form;

use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form for deleting a Feed.
 */
class FeedDeleteForm extends ContentEntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete the feed %feed?', array('%feed' => $this->entity->label()));
  }

  /**
   * {@inheritdoc}
   *
   * @todo Set the correct route once views can override paths.
   */
  public function getCancelUrl() {
    return $this->entity->urlInfo();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->entity->delete();

    $args = array('@importer' => $this->entity->getImporter()->label(), '%title' => $this->entity->label());
    $this->logger('feeds')->notice('@importer: deleted %title.', $args);
    drupal_set_message($this->t('%title has been deleted.', $args));

    $form_state->setRedirect('feeds.admin');
  }

}
