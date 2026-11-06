<?php

namespace Drupal\markaspot_service_provider;

/**
 * Defines events for the markaspot_service_provider module.
 */
final class ServiceProviderEvents {

  /**
   * Event fired when a service provider submits a response.
   *
   * @Event
   */
  const RESPONSE_SUBMITTED = 'markaspot_service_provider.response_submitted';

  /**
   * Event fired by cron when a provider notification is due for a request.
   *
   * Dispatched once per service request and notification interval while
   * "enable_cron_notifications" is on. The event object is a
   * \Drupal\markaspot_service_provider\Event\ServiceProviderNotificationDueEvent.
   *
   * @Event
   */
  const NOTIFICATION_DUE = 'markaspot_service_provider.notification_due';

}
