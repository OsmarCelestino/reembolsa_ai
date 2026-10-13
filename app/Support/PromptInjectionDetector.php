<?php

namespace App\Support;

/**
 * Heurística barata para detectar texto que tenta dar ordens à IA.
 *
 * Não é a defesa principal: a IA só preenche um schema e nunca decide nada.
 * Isto serve para sinalizar o pedido e mandá-lo para revisão humana.
 */
final class PromptInjectionDetector
{
    /** @var list<string> */
    private const PATTERNS = [
        '/\b(ignore|disregard|forget)\b.{0,40}\b(previous|above|prior|all)\b.{0,20}\b(instructions?|rules?|prompts?)\b/i',
        '/\b(ignore|desconsidere|esque[çc]a)\b.{0,40}\b(instru[çc][õo]es|regras|comandos)\b/iu',
        '/\b(system|developer)\s*(prompt|message|instructions?)\b/i',
        '/\byou are (now )?(an?|the) (ai|assistant|model)\b/i',
        '/\bvoc[êe] (agora )?[ée] (um|uma|o|a) (ia|assistente|modelo)\b/iu',
        '/\b(approve|aprove|aprovar)\b.{0,30}\b(this|este|esse|automatically|automaticamente)\b/iu',
        '/\b(set|defina|coloque)\b.{0,30}\bconfidence\b/i',
        '/<\/?(system|instructions?|prompt|tool)[^>]*>/i',
        '/\b(jailbreak|DAN mode)\b/i',
    ];

    public static function looksLikeInjection(?string ...$texts): bool
    {
        foreach ($texts as $text) {
            if ($text === null || $text === '') {
                continue;
            }

            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
