<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/** A IA respondeu, mas a resposta não serve (fora do schema, sem a ferramenta, requisição recusada). Repetir não adianta: vai para analista. */
final class ExtractionFailed extends RuntimeException {}
