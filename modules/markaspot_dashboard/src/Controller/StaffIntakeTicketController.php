<?php

declare(strict_types=1);

namespace Drupal\markaspot_dashboard\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\markaspot_dashboard\Service\StaffIntakeTicket;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issues one-use intake tickets to a workspace's authenticated staff.
 */
final class StaffIntakeTicketController implements ContainerInjectionInterface {

  /**
   * Constructs the controller.
   */
  public function __construct(private readonly StaffIntakeTicket $tickets) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('markaspot_dashboard.staff_intake_ticket'));
  }

  /**
   * Issues a ticket without creating a report or persisting citizen consent.
   */
  public function issue(Request $request): JsonResponse {
    try {
      $body = json_decode($request->getContent(), TRUE, 16, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return $this->response(['error' => 'Invalid workspace.'], 400);
    }
    $workspace = is_array($body) ? ($body['workspace_id'] ?? NULL) : NULL;
    if (!is_int($workspace) || $workspace <= 0) {
      return $this->response(['error' => 'Invalid workspace.'], 400);
    }
    $ticket = $this->tickets->issue($request, $workspace);
    return $ticket === NULL
      ? $this->response(['error' => 'Staff intake is not available.'], 403)
      : $this->response(['staff_intake_ticket' => $ticket]);
  }

  /**
   * Ticket responses must never be stored by intermediaries or the browser.
   */
  private function response(array $data, int $status = 200): JsonResponse {
    return new JsonResponse($data, $status, ['Cache-Control' => 'private, no-store']);
  }

}
