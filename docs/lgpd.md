# LGPD

Como o Reembolsa AI trata dados pessoais. É a visão técnica do projeto: as bases legais e os prazos devem ser confirmados com o jurídico e o encarregado (DPO) da empresa que usar o sistema.

## Que dados pessoais existem

| Dado | Onde fica | Por quê |
|---|---|---|
| Nome, e-mail e senha (hash) do funcionário | `users` | Login e identificação de quem pede e de quem revisa |
| Arquivo do comprovante | Disco privado (`storage/app/private/receipts`) | Prova da despesa. Pode conter CPF, nome e endereço de quem pagou |
| Descrição livre do pedido | `reimbursements.description` | Contexto para o analista. Texto livre, pode conter qualquer coisa |
| Dados extraídos (CNPJ, emitente, data, valor) | `reimbursements.extracted` | Dados da empresa emitente, não da pessoa. Sustentam a contabilidade |
| Quem fez o quê e quando | `reimbursement_audit_logs` | Rastreabilidade de aprovações e de acessos ao comprovante |

## Princípios aplicados no código

- **Finalidade e minimização:** a IA recebe só o arquivo do comprovante. A descrição digitada pelo usuário nunca é enviada. O schema da extração não tem campo para dados de pessoa física, e o prompt manda não transcrever CPF, nome do consumidor, telefone ou endereço (`AnthropicReceiptExtractor`). Há teste para os dois pontos (`tests/Feature/LgpdTest.php`).
- **Webhook sem dados pessoais:** só ids, categoria, valor e status.
- **Logs sem dados pessoais:** registram ids, status, códigos de regra, modelo e latência. Nunca o conteúdo da nota, a descrição ou a chave da API.
- **Acesso restrito:** o comprovante fica em disco privado com nome aleatório, nunca em URL pública. Só o dono e analistas baixam, por uma rota autenticada; quem tenta ver o pedido de outra pessoa recebe 404.
- **Rastreabilidade:** cada download do comprovante e cada mudança de status ficam na auditoria, com quem fez. O dono do pedido vê essa linha do tempo.
- **Segurança:** HTTPS na frente do Nginx, tokens que expiram em 8h, rate limit, segredos só no `.env`, que nunca entra no Git nem na imagem Docker.

## Retenção

| O quê | Por quanto tempo | Como |
|---|---|---|
| Arquivo do comprovante e descrição livre | `REIMBURSEMENT_RETENTION_DAYS` (padrão 1825 dias, cerca de 5 anos, prazo usual de guarda de documentos fiscais) depois do pedido, só para pedidos encerrados | `reembolso:purge-receipts`, agendado todo dia às 03:15. Registra `receipt_purged` na auditoria; o download passa a responder 410 |
| Dados extraídos, valores, status e auditoria | Mantidos | Necessários para a contabilidade e para provar as decisões |
| Pedidos ainda em aberto | Até serem encerrados | Não são descartados |

Ao excluir um pedido pela aplicação, o arquivo é apagado junto (evento `deleted` do modelo).

## Direitos do titular

| Pedido do titular | Como atender |
|---|---|
| Acesso | `GET /api/reimbursements` e o detalhe de cada pedido, com dados extraídos e linha do tempo |
| Eliminação | `php artisan reembolso:anonymize-user {id}`: troca nome e e-mail, invalida a senha, revoga tokens e apaga as descrições livres. Os pedidos ficam, sem dados pessoais, porque a empresa precisa guardá-los por obrigação legal |
| Correção | Nome e e-mail ficam na tabela `users` (fora do escopo desta API) |

## Operador externo: Anthropic

O comprovante é enviado à API da Anthropic para extração, o que faz dela uma operadora de dados pessoais. Antes de usar em produção:

- Confira os termos comerciais e o acordo de tratamento de dados (DPA) da Anthropic: retenção na API, uso para treinamento e transferência internacional.
- Registre a transferência internacional e o operador no registro de operações de tratamento (ROPA) da empresa.
- Use só modelos da lista `LLM_ALLOWED_MODELS`, aprovada pela empresa.
