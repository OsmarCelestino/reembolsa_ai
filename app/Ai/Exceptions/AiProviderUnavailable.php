<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** Falha do provedor (rede, chave, limite, 5xx). Pode passar ao tentar de novo: o job refaz com espera. */
final class AiProviderUnavailable extends RuntimeException {}
