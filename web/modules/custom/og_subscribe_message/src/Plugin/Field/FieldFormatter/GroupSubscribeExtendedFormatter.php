<?php

namespace Drupal\og_subscribe_message\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RedirectDestinationTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\og\Og;
use Drupal\og\OgAccessInterface;
use Drupal\og\OgMembershipInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation for the OG subscribe formatter.
 *
 * @FieldFormatter(
 *   id = "og_group_subscribe_extended",
 *   label = @Translation("OG Group subscribe extended"),
 *   description = @Translation("Display OG Group subscribe and un-subscribe links."),
 *   field_types = {
 *     "og_group"
 *   }
 * )
 */
class GroupSubscribeExtendedFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  use RedirectDestinationTrait;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The OG access service.
   *
   * @var \Drupal\og\OgAccessInterface
   */
  protected $ogAccess;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'subscribe_message' => 'Subscribe to group',
        'unsubscribe_message' => 'Unsubscribe from group',
        'request_subscription_message' => 'Request group membership',
        'closed_group_message' => 'This is a closed group. Only a group administrator can add you.',
        'manager_message' => 'You are the group manager',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['subscribe_message'] = [
      '#title' => $this->t('Subscribe message'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('subscribe_message'),
    ];

    $form['unsubscribe_message'] = [
      '#title' => $this->t('Unsubscribe message'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('unsubscribe_message'),
    ];

    $form['request_subscription_message'] = [
      '#title' => $this->t('Request subscription message'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('request_subscription_message'),
    ];

    $form['closed_group_message'] = [
      '#title' => $this->t('Message shown if group is closed'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('closed_group_message'),
    ];

    $form['manager_message'] = [
      '#title' => $this->t('Message shown to the group manager about ownership'),
      '#type' => 'textfield',
      '#default_value' => $this->getSetting('manager_message'),
    ];

    return $form;
  }

  /**
   * Constructs a new GroupSubscribeFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\og\OgAccessInterface $og_access
   *   The OG access service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, OgAccessInterface $og_access, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);

    $this->currentUser = $current_user;
    $this->ogAccess = $og_access;
    $this->entityTypeManager = $entity_type_manager->getStorage('user');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('current_user'),
      $container->get('og.access'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // Cache by the OG membership state.
    $elements['#cache']['contexts'] = ['og_membership_state'];

    $group = $items->getEntity();
    $entity_type_id = $group->getEntityTypeId();

    // $user = User::load($this->currentUser->id());
    $user = $this->entityTypeManager->load(($this->currentUser->id()));
    if (($group instanceof EntityOwnerInterface) && ($group->getOwnerId() == $user->id())) {
      $managerMessage = $this->getStringSetting('manager_message');
      // User is the group manager.
      $elements[0] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'title' => $managerMessage,
          'class' => ['group', 'manager'],
        ],
        '#value' => $managerMessage,
      ];

      return $elements;
    }

    if (Og::isMemberBlocked($group, $user)) {
      // If user is blocked, they should not be able to apply for
      // membership.
      return $elements;
    }

    if (Og::isMember($group, $user, [OgMembershipInterface::STATE_ACTIVE, OgMembershipInterface::STATE_PENDING])) {
      $link['title'] = $this->getStringSetting('unsubscribe_message');
      $link['url'] = Url::fromRoute('og.unsubscribe', ['entity_type_id' => $entity_type_id, 'group' => $group->id()]);
      $link['class'] = ['unsubscribe'];
    }
    else {
      // If the user is authenticated, set up the subscribe link.
      if ($user->isAuthenticated()) {
        $parameters = [
          'entity_type_id' => $group->getEntityTypeId(),
          'group' => $group->id(),
        ];

        $url = Url::fromRoute('og.subscribe', $parameters);
      }
      else {
        // User is anonymous, link to user login and redirect back to here.
        $url = Url::fromRoute('user.login', [], ['query' => $this->getDestinationArray()]);
      }

      /** @var \Drupal\Core\Access\AccessResult $access */
      if (($access = $this->ogAccess->userAccess($group, 'subscribe without approval', $user)) && $access->isAllowed()) {
        $link['title'] = $this->getStringSetting('subscribe_message');;
        $link['class'] = ['subscribe'];
        $link['url'] = $url;
      }
      elseif (($access = $this->ogAccess->userAccess($group, 'subscribe', $user)) && $access->isAllowed()) {
        $link['title'] = $this->getStringSetting('request_subscription_message');
        $link['class'] = ['subscribe', 'request'];
        $link['url'] = $url;
      }
      else {
        $closedGroupMessage = $this->getStringSetting('closed_group_message');
        $elements[0] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'title' => $closedGroupMessage,
            'class' => ['group', 'closed'],
          ],
          '#value' => $closedGroupMessage,
        ];

        return $elements;
      }
    }

    if (!empty($link['title'])) {
      $link += [
        'options' => [
          'attributes' => [
            'title' => $link['title'],
            'class' => ['group'] + $link['class'],
          ],
        ],
      ];

      $elements[0] = [
        '#type' => 'link',
        '#title' => $link['title'],
        '#url' => $link['url'],
      ];
    }

    return $elements;
  }

  /**
   * Get config string and change tokens to real data.
   *
   * @param string $config
   *   Config machine name.
   *
   * @return string
   *   Processed string.
   */
  private function getStringSetting(string $config) {
    $tokenService = \Drupal::token();
    $string = $this->getSetting($config);

    return $tokenService->replace($string);
  }

}
