// Exemplo 3: mapeamento de campos com tipos de dados.
// Converte contatos do CRM para o formato da plataforma de e-mail marketing
// e envia os leads com pontuação alta para a equipe de vendas.
//
// Demonstra: várias APIs e integrações no mesmo arquivo, bloco "map" com os
// quatro tipos de dados (string, int, float, bool), operadores aritméticos,
// concatenação de textos, negação lógica, comparação com null e uso de
// palavra reservada como nome de campo JSON (contato.from).

api Crm {
    base "https://crm.example.com/v1";
    header "Authorization" = "Bearer " + env("CRM_TOKEN");
    endpoint listContacts GET "/contacts";
}

api Mailer {
    base "https://mailer.example.com/api";
    endpoint upsertSubscriber PUT "/subscribers";
}

api Vendas {
    base "https://vendas.example.com";
    endpoint criarLead POST "/leads";
}

integration sincronizarAssinantes {
    from Crm.listContacts as contato;
    to Mailer.upsertSubscriber;
    when contato.email != null && contato.optIn;
    map {
        email: string = contato.email;
        fullName: string = contato.firstName + " " + contato.lastName;
        age: int = 2026 - contato.birthYear;
        active: bool = !contato.archived;
    }
}

integration encaminharLeadsQuentes {
    from Crm.listContacts as contato;
    to Vendas.criarLead;
    when contato.score * 1.5 >= 75 && contato.from != "partner";
    map {
        nome: string = contato.firstName;
        pontuacao: float = contato.score * 1.5;
        origem: string = "crm";
    }
}
