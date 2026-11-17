# Gramática (escopo preliminar)

> **Status: EM DEFINIÇÃO — esta NÃO é a gramática final.**
> A gramática livre de contexto (GLC) é responsabilidade do integrante do
> **Analisador Sintático**, em acordo com o responsável pelo **Analisador
> Léxico** (tokens) e pelo **Gerador** (AST).

## 1. Escopo mínimo da linguagem

A DSL deve permitir descrever:

1. **APIs**: identificador + endereço-base + endpoints.
2. **Endpoints**: identificador + método HTTP + caminho.
3. **Integrações**: endpoint de origem → endpoint de destino.

Além disso, o enunciado do trabalho exige elementos que ainda precisam ser
incorporados (ver pendências): **tipos de dados**, **operadores** e
**estrutura condicional**.

## 2. Esboço ilustrativo (não normativo)

Apenas para orientar a discussão. Palavras-chave, delimitadores e formato
**não estão decididos**.

```text
api Users {
    base "https://users.example.com/api"
    endpoint listUsers GET "/users"
}

api Crm {
    base "https://crm.example.com/v1"
    endpoint createContact POST "/contacts"
}

integration syncUsers {
    from Users.listUsers
    to Crm.createContact
}
```

Este esboço corresponde à AST de exemplo em [`ast-contract.md`](ast-contract.md).

## 3. Requisitos para a GLC final

O documento final deverá conter:

- conjunto de **terminais** (referência aos tokens de [`tokens.md`](tokens.md));
- conjunto de **não terminais** e **símbolo inicial**;
- **produções** em notação BNF ou EBNF;
- justificativa da escolha da técnica de análise (ex.: descendente recursiva);
- verificação de ausência de recursão à esquerda e ambiguidades, se a técnica exigir;
- exemplos de derivação para ao menos um programa válido;
- exemplos de erros sintáticos e mensagens esperadas.

## 4. Pendências

| # | Questão |
|---|---|
| G1 | Palavras-chave definitivas (`api`, `endpoint`, `integration`, `from`, `to`, …) |
| G2 | Delimitação de blocos: chaves, `begin`/`end` ou indentação? |
| G3 | Separador de declarações: quebra de linha, `;` ou nenhum? |
| G4 | Forma de referenciar endpoints (`Api.endpoint`?) |
| G5 | Onde entram **tipos de dados** (declaração de campos, variáveis, literais?) |
| G6 | **Operadores** suportados e sua precedência/associatividade |
| G7 | Sintaxe da **estrutura condicional** (ex.: `when <condição>` dentro de `integration`) |
| G8 | Mapeamento de campos, cabeçalhos e autenticação fazem parte da gramática? |
| G9 | Ordem obrigatória: APIs antes das integrações? |
