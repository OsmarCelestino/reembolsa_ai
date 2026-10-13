# Arquitetura

## Escolha: Laravel "padrão" + Actions + um núcleo de regras puro

Camadas completas (DDD/hexagonal com repositórios, mappers e casos de uso para tudo) seriam exagero
para um sistema deste tamanho. Deixar tudo no controller mistura HTTP, IA e regra de negócio e
torna impossível testar a regra sozinha. O meio-termo:

| Peça | Onde | Responsabilidade |
|---|---|---|
| FormRequest | `app/Http/Requests` | Validar entrada (tipos, enum, upload) |
| Policy | `app/Policies` | Quem pode ver / revisar |
| Controller | `app/Http/Controllers/Api` | Só liga as peças (fino) |
| Action | `app/Actions/Reimbursements` | Um caso de uso por classe (enviar, processar, revisar) |
| Regras | `app/Domain/Reimbursement` | Regra de negócio pura, sem banco/HTTP/IA |
| Porta de IA | `app/Ai/Contracts/ReceiptExtractor` | Interface; a implementação Anthropic fica em `app/Ai` |
| Job | `app/Jobs` | Processamento assíncrono com retry, via RabbitMQ (testado de verdade em `tests/Feature/Queue`) |
| Suporte | `app/Support` | Utilitários puros: `Money` (centavos), `Cnpj` (inclusive alfanumérico), `Text` (limpeza), `PromptInjectionDetector` |
| Demo e avaliação | `app/Demo`, `app/Eval` | Comprovantes fictícios com gabarito para `reembolso:demo` e `eval:run` |

Eloquent é usado direto nas Actions (sem repositórios): é o padrão do Laravel e não esconde nada.
A única abstração "extra" é a interface da IA, porque ela é externa, cara e instável — e precisa de fake nos testes.

## Fluxo

```
POST /api/reimbursements
  → StoreReimbursementRequest (valida)        → 422 se inválido
  → SubmitReimbursement (salva arquivo privado, status=submitted)
  → 202 Accepted + ProcessReimbursementJob publicado no RabbitMQ (só após o commit)

ProcessReimbursementJob
  → ProcessReimbursement
      trava a linha (submitted → processing)   # idempotente
      ReceiptExtractor::extract()              # IA só EXTRAI
      ReimbursementRules::evaluate()           # código DECIDE
      status = auto_approved | pending_review | rejected

POST /api/reimbursements/{id}/review           # analista, nunca no próprio pedido
  → ReviewReimbursement (pending_review → approved | rejected)
```

Se a IA falhar de forma temporária (`AiProviderUnavailable`), o job volta para o RabbitMQ com espera (filas `default.delay.<ms>`)
e tenta de novo; esgotadas as 3 tentativas, o pedido vira `failed` e o job vai para a tabela `failed_jobs`.
Se a resposta da IA for inválida (`ExtractionFailed`), o pedido vai direto para `pending_review`.

## Segurança

**Prompt injection**
- A IA não decide nada. Ela só pode chamar a ferramenta `register_receipt` (`tool_choice` forçado), que é um formulário.
- O system prompt declara o comprovante como dado não confiável e pede para sinalizar `suspicious_content`.
- A resposta é revalidada (schema, formatos, faixas, CNPJ com dígito verificador). Texto livre passa por limpeza.
- Uma heurística local também procura texto de comando no que a IA devolveu.
- Qualquer suspeita → revisão humana. Mesmo com a IA manipulada, as regras determinísticas (valor ≤ nota,
  validade, duplicidade, limites) continuam valendo.
- A descrição digitada pelo usuário nunca é enviada à IA.

**SQL injection e entrada**
- Só Eloquent/query builder com bindings; nenhum SQL concatenado.
- Ordenação e filtros por lista branca (`ListReimbursementsRequest::SORTABLE`, enums).
- Dinheiro em centavos (inteiro), convertido de string sem float.
- Mass assignment: só `category`, `claimed_amount_cents` e `description` são preenchíveis; o resto só muda pelas Actions.
- `Model::shouldBeStrict()` em dev/teste.

**Upload**
- Tipo detectado pelo conteúdo (finfo), não pela extensão; extensão também precisa estar na lista.
- Nome aleatório no disco privado; o caminho nunca sai na API.
- Hash SHA-256 bloqueia reenvio do mesmo arquivo; impressão digital (CNPJ+data+valor) bloqueia o mesmo comprovante em outro arquivo.

**Acesso**
- Sanctum com token que expira em 8h; rate limit em login e envio.
- Funcionário só vê os próprios pedidos (404 para os demais). Analista não revisa o próprio pedido.
- Só modelos de IA em `LLM_ALLOWED_MODELS` podem ser usados. Chave só no `.env` (fora do Git).

## Cenário de demonstração

`php artisan reembolso:demo` gera 2 notas fictícias e roda o fluxo inteiro com a IA real, comparando com um gabarito
(`app/Demo/DemoReceipts.php`). A nota 2 traz uma tentativa de prompt injection e um valor inflado: o resultado
esperado é `rejected`, independentemente do que a IA responder. Teste equivalente com IA simulada:
`tests/Feature/DemoScenarioTest.php`.

## Próximos passos
- Camunda para o fluxo de aprovação.
- RAG com pgvector sobre a política de reembolso.
- Ampliar o `eval:run` com comprovantes reais anonimizados (ver [avaliacao.md](avaliacao.md)).
