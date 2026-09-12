# CONTEXT.md — Null Safety Test Generator

## 1. Visão geral

**Null Safety Test Generator** é um pacote PHP destinado a ser instalado em aplicações Laravel.

O objetivo do pacote é analisar automaticamente partes da aplicação Laravel para identificar situações em que valores `null` podem provocar erros durante a execução de uma rota, especialmente erros HTTP 500 causados durante a renderização de views Blade.

Além da análise, o pacote deverá gerar automaticamente casos de teste que reproduzam hipóteses de nulidade utilizando as factories dos Models envolvidos.

---

## 2. Problema que o projeto pretende resolver

Considere uma view Blade que contenha:

```blade
{{ $proposicao->tramitacao->data->format('d/m/Y') }}
```

Existem pelo menos duas situações relevantes:

### Relação nula

Se:

```php
$proposicao->tramitacao === null
```

o acesso:

```php
$proposicao->tramitacao->data
```

pode provocar erro por tentativa de acessar uma propriedade em `null`.

### Atributo nulo usado como objeto

Se a tramitação existir, mas:

```php
$proposicao->tramitacao->data === null
```

então:

```php
$proposicao->tramitacao->data->format('d/m/Y')
```

pode provocar erro por tentativa de chamar `format()` em `null`.

Por outro lado:

```blade
{{ $proposicao->tramitacao->descricao }}
```

não necessariamente produz erro caso `descricao` seja `null`, pois o Blade pode renderizar esse valor como vazio.

O pacote deverá distinguir situações potencialmente perigosas de simples valores nulos que não provocam falha.

---

## 3. Objetivo final

O fluxo desejado é aproximadamente:

```text
Rotas Laravel
    ↓
Controller + método
    ↓
Objetos disponibilizados pelo Controller
    ↓
View retornada
    ↓
Análise do Blade
    ↓
Relacionamentos e atributos acessados
    ↓
Identificação de possíveis estados null
    ↓
Geração de cenários
    ↓
Factories dos Models
    ↓
Feature/Integration Tests
    ↓
Requisição HTTP
    ↓
Verificação de ausência de erro 5xx
```

O pacote deverá assumir inicialmente que os Models utilizados possuem factories correspondentes.

---

## 4. Estratégia de teste

Os testes gerados serão testes de integração orientados a requisições HTTP.

Eles exercitarão, conforme o caso:

```text
Router
Controller
Route Model Binding
Eloquent
Banco de dados de teste
Blade
Resposta HTTP
```

A abordagem também possui características de:

- teste negativo;
- injeção de falhas (fault injection);
- geração automática de testes baseada em análise estática.

O objetivo principal não é simplesmente verificar se a página retorna `200`, mas garantir que estados nulos previstos não provoquem falhas HTTP 5xx.

Exemplo conceitual:

```php
$response = $this->get(
    route('proposicoes.show', $proposicao)
);

$this->assertLessThan(500, $response->status());
```

A política exata dos status HTTP aceitáveis ainda poderá ser refinada.

---

## 5. Escopo inicial

A primeira versão deverá priorizar aplicações Laravel convencionais, especialmente:

- Controllers;
- rotas GET;
- Route Model Binding;
- Eloquent;
- Models com Factory;
- relacionamentos Eloquent tradicionais;
- views Blade;
- chamadas convencionais a `view()`.

Não é necessário inicialmente suportar todos os padrões possíveis de Laravel, como:

- Inertia;
- Livewire;
- closures complexas em rotas;
- View Components complexos;
- repositories arbitrários;
- inferência completa de tipos dinâmica.

O desenvolvimento deverá evoluir incrementalmente, orientado por testes.

---

## 6. Estrutura inicial do projeto

A estrutura começou como:

```text
null-safety-test-generator/
├── src
└── tests
```

A organização planejada inclui, inicialmente:

```text
null-safety-test-generator/
├── composer.json
├── CONTEXT.md
├── src/
│   └── Analyzers/
│       └── ControllerMethodAnalyzer.php
└── tests/
    ├── Fixtures/
    │   ├── FakeController.php
    │   ├── FakeObject.php
    │   └── AnotherFakeObject.php
    └── Unit/
        └── ControllerMethodAnalyzerTest.php
```

O projeto deverá ser desenvolvido e testado como pacote PHP independente antes de ser conectado a uma aplicação Laravel real.

---

## 7. Dependências relevantes

Para análise do código-fonte PHP será utilizado:

```text
nikic/php-parser
```

Instalação:

```bash
composer require nikic/php-parser
```

Para testes:

```text
PHPUnit
```

---

# 8. ControllerMethodAnalyzer

O primeiro componente em desenvolvimento é:

```text
ControllerMethodAnalyzer
```

Sua responsabilidade atual é descobrir quais variáveis de um método de Controller representam objetos e qual é a classe associada a cada variável.

A análise deve contemplar pelo menos duas origens:

1. objetos recebidos como parâmetros tipados;
2. objetos carregados/criados dentro do método.

A API atualmente planejada é:

```php
$analyzer = new ControllerMethodAnalyzer();

$result = $analyzer->getObjectClasses(
    FakeController::class,
    'show'
);
```

---

## 9. Objetos recebidos como parâmetro

Exemplo:

```php
public function show(FakeObject $object)
{
    //
}
```

O analyzer deve identificar:

```text
$object → FakeObject
```

Para isso é utilizada a Reflection nativa do PHP:

```php
ReflectionMethod
ReflectionNamedType
```

Estratégia:

```php
private function getParameterClasses(
    string $controllerClass,
    string $method
): array
```

A Reflection deve:

1. obter o método;
2. percorrer seus parâmetros;
3. verificar o tipo de cada parâmetro;
4. ignorar tipos built-in;
5. relacionar o nome da variável à classe.

Tipos como estes devem ser ignorados:

```text
string
int
float
bool
array
```

Classes devem ser consideradas:

```text
FakeObject
Proposicao
Tramitacao
User
```

Union types e intersection types ainda não fazem parte do escopo inicial.

---

# 10. Objetos carregados dentro do método

Reflection não é suficiente para casos como:

```php
public function show(int $id)
{
    $proposicao = Proposicao::find($id);
}
```

Por isso, variáveis locais são analisadas através da AST gerada pelo `nikic/php-parser`.

Método responsável:

```php
private function getLocalVariableClasses(
    string $controllerClass,
    string $method
): array
```

O fluxo básico é:

```text
ReflectionMethod
    ↓
descobrir arquivo PHP
    ↓
file_get_contents()
    ↓
ParserFactory
    ↓
AST
    ↓
NameResolver
    ↓
NodeFinder
    ↓
ClassMethod
    ↓
Assign
    ↓
identificação da classe raiz
```

---

# 11. NameResolver

A AST deve ser processada com:

```php
PhpParser\NodeTraverser
PhpParser\NodeVisitor\NameResolver
```

Exemplo:

```php
use App\Models\Proposicao;

$proposicao = Proposicao::find(1);
```

Após resolução, o analyzer deverá conseguir trabalhar com:

```text
App\Models\Proposicao
```

e não apenas:

```text
Proposicao
```

Estrutura:

```php
$traverser = new NodeTraverser();

$traverser->addVisitor(
    new NameResolver()
);

$ast = $traverser->traverse($ast);
```

Isso é importante para que os resultados da AST sejam compatíveis com resultados obtidos por Reflection e com `Model::class`.

---

# 12. Chamadas estáticas simples

O analyzer deve reconhecer inicialmente:

```php
$object = FakeObject::find(1);
```

e produzir uma associação entre:

```text
object
```

e:

```text
FakeObject::class
```

Outros exemplos do mesmo formato:

```php
$object = FakeObject::first();

$object = FakeObject::create([...]);
```

---

# 13. Chamadas encadeadas

O analyzer também deve reconhecer chamadas típicas do Laravel como:

```php
$object = FakeObject::query()->first();
```

e:

```php
$object = FakeObject::query()
    ->where('active', true)
    ->first();
```

ou ainda:

```php
$object = FakeObject::where('active', true)
    ->orderBy('id')
    ->first();
```

A AST dessas expressões é formada por `MethodCall`s encadeados até chegar a uma `StaticCall`.

Exemplo conceitual:

```text
MethodCall first()
    ↓
MethodCall where()
    ↓
StaticCall query()
    ↓
FakeObject
```

Foi proposta uma busca recursiva pela classe raiz:

```php
private function getRootClassFromExpression(
    Node\Expr $expr
): ?string {
    if ($expr instanceof Node\Expr\StaticCall) {
        if (! $expr->class instanceof Node\Name) {
            return null;
        }

        return $expr->class->toString();
    }

    if ($expr instanceof Node\Expr\MethodCall) {
        return $this->getRootClassFromExpression(
            $expr->var
        );
    }

    return null;
}
```

Essa abordagem permite identificar a classe raiz independentemente da quantidade de chamadas encadeadas, desde que a cadeia tenha origem em uma chamada estática reconhecível.

---

# 14. Objetos versus coleções

O analyzer deverá distinguir uma variável que representa um objeto individual de uma variável que representa uma coleção de objetos.

A estrutura de retorno evoluiu de:

```php
[
    'object' => FakeObject::class,
]
```

para algo com metadados:

```php
[
    'object' => [
        'class' => FakeObject::class,
        'type' => 'object',
    ],
]
```

Para coleções:

```php
[
    'objects' => [
        'class' => FakeObject::class,
        'type' => 'collection',
    ],
]
```

---

## 15. Casos de objeto individual

Devem inicialmente ser considerados retornos de objeto métodos como:

```text
find
findOrFail
first
firstOrFail
sole
create
firstOrCreate
firstOrNew
```

Exemplo:

```php
$object = FakeObject::query()
    ->where('active', true)
    ->first();
```

Resultado esperado:

```php
[
    'object' => [
        'class' => FakeObject::class,
        'type' => 'object',
    ],
]
```

---

## 16. Casos de coleção

Devem inicialmente ser reconhecidos como coleção métodos como:

```text
get
all
pluck
```

Exemplo:

```php
$objects = FakeObject::query()
    ->where('active', true)
    ->get();
```

Resultado esperado:

```php
[
    'objects' => [
        'class' => FakeObject::class,
        'type' => 'collection',
    ],
]
```

Também:

```php
$objects = FakeObject::all();
```

deve ser identificado como coleção.

Observação: a lista de métodos que retornam objetos e coleções deverá evoluir conforme novos casos reais forem adicionados aos testes.

---

# 17. Identificação do método terminal

Para distinguir objetos de coleções, foi proposta a inspeção do último método chamado na expressão.

Exemplo:

```php
private function getLastCalledMethod(
    Node\Expr $expr
): ?string {
    if (
        $expr instanceof Node\Expr\MethodCall
        && $expr->name instanceof Node\Identifier
    ) {
        return $expr->name->toString();
    }

    if (
        $expr instanceof Node\Expr\StaticCall
        && $expr->name instanceof Node\Identifier
    ) {
        return $expr->name->toString();
    }

    return null;
}
```

Exemplos:

```php
FakeObject::query()->first()
```

método terminal:

```text
first
```

Tipo:

```text
object
```

Enquanto:

```php
FakeObject::query()->where(...)->get()
```

método terminal:

```text
get
```

Tipo:

```text
collection
```

---

# 18. Fixtures e cenários de teste atuais

Um Controller de teste pode conter algo como:

```php
class FakeController
{
    public function show(FakeObject $object)
    {
        $firstObject = AnotherFakeObject::query()
            ->where('active', true)
            ->first();

        $objects = AnotherFakeObject::query()
            ->where('active', true)
            ->get();

        $allObjects = AnotherFakeObject::all();

        return [
            'object' => $object,
            'firstObject' => $firstObject,
            'objects' => $objects,
            'allObjects' => $allObjects,
        ];
    }
}
```

O resultado esperado do analyzer é:

```php
[
    'object' => [
        'class' => FakeObject::class,
        'type' => 'object',
    ],

    'firstObject' => [
        'class' => AnotherFakeObject::class,
        'type' => 'object',
    ],

    'objects' => [
        'class' => AnotherFakeObject::class,
        'type' => 'collection',
    ],

    'allObjects' => [
        'class' => AnotherFakeObject::class,
        'type' => 'collection',
    ],
]
```

---

# 19. Testes recomendados para ControllerMethodAnalyzer

Os testes devem ser pequenos e específicos, além de eventualmente haver um teste de integração interna do analyzer.

Casos importantes:

### 19.1 Parâmetro tipado

```php
public function show(FakeObject $object)
```

Deve identificar:

```text
object → FakeObject → object
```

### 19.2 Chamada estática

```php
$object = AnotherFakeObject::find(1);
```

Deve identificar:

```text
object → AnotherFakeObject → object
```

### 19.3 Uma chamada encadeada

```php
$object = AnotherFakeObject::query()->first();
```

Deve identificar:

```text
object → AnotherFakeObject → object
```

### 19.4 Várias chamadas encadeadas

```php
$object = AnotherFakeObject::query()
    ->where(...)
    ->first();
```

Deve identificar a mesma classe raiz.

### 19.5 Collection via get()

```php
$objects = AnotherFakeObject::query()
    ->where(...)
    ->get();
```

Deve identificar:

```text
objects → AnotherFakeObject → collection
```

### 19.6 Collection via all()

```php
$objects = AnotherFakeObject::all();
```

Deve identificar:

```text
objects → AnotherFakeObject → collection
```

### 19.7 Mistura de parâmetros e variáveis locais

O resultado final deve conter ambos sem que uma estratégia de análise elimine a outra.

---

# 20. Geração e inspeção manual da AST

Durante o desenvolvimento, pode ser usado um script temporário para visualizar a AST de uma fixture.

Exemplo:

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use PhpParser\NodeDumper;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

$code = file_get_contents(
    __DIR__ . '/tests/Fixtures/FakeController.php'
);

$parser = (new ParserFactory())
    ->createForNewestSupportedVersion();

$ast = $parser->parse($code);

$traverser = new NodeTraverser();

$traverser->addVisitor(
    new NameResolver()
);

$ast = $traverser->traverse($ast);

$dumper = new NodeDumper();

echo $dumper->dump($ast);
```

Execução:

```bash
php ast.php
```

Ou:

```bash
php ast.php > ast.txt
```

Isso permite visualizar exatamente a AST que o `ControllerMethodAnalyzer` está analisando.

---

# 21. Próximas etapas previstas

Após estabilizar o `ControllerMethodAnalyzer`, os próximos componentes deverão ser desenvolvidos incrementalmente.

Uma sequência provável é:

```text
1. ControllerMethodAnalyzer
   ↓
2. identificação da view retornada
   ↓
3. identificação das variáveis enviadas à view
   ↓
4. Blade/View Analyzer
   ↓
5. análise de cadeias de acesso
   ↓
6. Eloquent Relationship Resolver
   ↓
7. Null Risk Analyzer
   ↓
8. Test Scenario Generator
   ↓
9. Factory/Test Generator
   ↓
10. integração com rotas Laravel
   ↓
11. comando Artisan
```

---

# 22. Identificação futura da view

Um Controller típico poderá conter:

```php
public function show(Proposicao $proposicao)
{
    $tramitacoes = Tramitacao::where(
        'proposicao_id',
        $proposicao->id
    )->get();

    return view('proposicoes.show', [
        'proposicao' => $proposicao,
        'tramitacoes' => $tramitacoes,
    ]);
}
```

O futuro analyzer deverá identificar:

```text
View:
proposicoes.show

Variáveis:
proposicao → Proposicao → object
tramitacoes → Tramitacao → collection
```

É importante analisar apenas os objetos efetivamente enviados à view, pois um Controller pode criar objetos intermediários que nunca são disponibilizados ao Blade.

---

# 23. Análise futura do Blade

Exemplo:

```blade
{{ $proposicao->tramitacao->data->format('d/m/Y') }}
```

O sistema deverá decompor essa expressão aproximadamente em:

```text
root:
proposicao

access:
tramitacao
data

method:
format
```

E produzir hipóteses como:

```text
H1:
Proposicao::tramitacao = null

H2:
Tramitacao::data = null
```

Outro exemplo:

```blade
{{ $proposicao->tramitacao->descricao }}
```

A análise deverá reconhecer que `descricao = null` não necessariamente representa o mesmo risco de:

```blade
{{ $proposicao->tramitacao->data->format(...) }}
```

---

# 24. Possíveis categorias futuras de risco

Uma ideia inicial é representar riscos como:

```text
PROPERTY_ON_NULL
METHOD_ON_NULL
SAFE_OUTPUT
```

Exemplo:

```php
$proposicao->tramitacao->data
```

pode representar:

```text
PROPERTY_ON_NULL
```

se `tramitacao` puder ser `null`.

Enquanto:

```php
$proposicao->data->format()
```

pode representar:

```text
METHOD_ON_NULL
```

se `data` puder ser `null`.

Essa classificação ainda não está fechada e deverá ser validada por testes.

---

# 25. Eloquent e relacionamentos

Em uma etapa posterior, o pacote deverá identificar relacionamentos como:

```php
class Proposicao extends Model
{
    public function tramitacao()
    {
        return $this->hasOne(Tramitacao::class);
    }
}
```

A análise poderá usar Reflection e/ou inspeção controlada do Model para determinar:

```text
Proposicao
    ↓ hasOne
Tramitacao
```

Isso será necessário para converter:

```blade
$proposicao->tramitacao->data
```

em conhecimento sobre quais factories e relacionamentos precisam ser manipulados nos testes.

---

# 26. Geração futura dos testes

O pacote deverá gerar arquivos em uma estrutura semelhante a:

```text
tests/
└── Feature/
    └── Generated/
        └── ProposicoesShowNullSafetyTest.php
```

Exemplo conceitual para relação nula:

```php
public function test_show_does_not_fail_when_tramitacao_is_null(): void
{
    $proposicao = Proposicao::factory()->create();

    $response = $this->get(
        route('proposicoes.show', $proposicao)
    );

    $this->assertLessThan(
        500,
        $response->status()
    );
}
```

Exemplo conceitual para atributo nulo:

```php
public function test_show_does_not_fail_when_tramitacao_data_is_null(): void
{
    $proposicao = Proposicao::factory()->create();

    Tramitacao::factory()->create([
        'proposicao_id' => $proposicao->id,
        'data' => null,
    ]);

    $response = $this->get(
        route('proposicoes.show', $proposicao)
    );

    $this->assertLessThan(
        500,
        $response->status()
    );
}
```

A forma definitiva de criação de relacionamentos deverá considerar as factories e relações Eloquent reais.

---

# 27. Integração futura com Laravel

Quando os analyzers estiverem maduros, o pacote será conectado a uma aplicação Laravel através do Composer.

Durante o desenvolvimento, a intenção é utilizar um repositório Composer do tipo `path`, evitando editar manualmente a pasta `vendor`.

O pacote deverá futuramente registrar um Service Provider e comandos Artisan.

Comandos planejados:

```bash
php artisan null-safety:scan
```

para analisar sem gerar arquivos, e:

```bash
php artisan null-safety:generate
```

para gerar os testes.

Poderá haver posteriormente um comando que faça análise, geração, execução e relatório.

---

# 28. Rotas

Quando a integração Laravel começar, não é necessário analisar literalmente apenas o arquivo `routes/web.php`.

A intenção é utilizar o Router do Laravel:

```php
Route::getRoutes();
```

Isso permitirá reconhecer rotas independentemente de terem sido declaradas diretamente, através de resources ou carregadas por outros arquivos.

O escopo inicial poderá filtrar rotas GET destinadas a Controllers.

---

# 29. Princípios de desenvolvimento

O projeto deve seguir estes princípios:

### Desenvolvimento incremental

Implementar um comportamento pequeno por vez.

### Testes antes de generalizações

Antes de adicionar suporte a um novo padrão de PHP/Laravel, criar uma fixture e um teste que demonstre o comportamento esperado.

### Evitar regex quando AST oferece informação estrutural

Análise de código PHP deve preferencialmente utilizar `nikic/php-parser`.

### Separação de responsabilidades

Evitar que uma única classe conheça rotas, Controllers, Blade, Eloquent, factories e geração de PHPUnit simultaneamente.

Uma arquitetura possível:

```text
RouteScanner
    ↓
ControllerMethodAnalyzer
    ↓
ViewResolver
    ↓
BladeAnalyzer
    ↓
EloquentResolver
    ↓
NullRiskAnalyzer
    ↓
TestScenario
    ↓
TestGenerator
```

### Objetos intermediários

Conforme o projeto crescer, preferir DTOs/value objects a arrays complexos para representar resultados de análise.

Exemplos futuros:

```text
AnalyzedVariable
ViewInfo
NullableAccess
NullRisk
TestScenario
```

---

# 30. Decisões ainda abertas

Não assumir como definitivas as seguintes questões:

1. lista completa de métodos Eloquent que retornam Model;
2. lista completa de métodos Eloquent que retornam Collection;
3. tratamento de `Builder`;
4. tratamento de `LazyCollection`;
5. tratamento de `Paginator`;
6. tratamento de `Collection` transformada por métodos posteriores;
7. union/intersection types;
8. variáveis criadas por `new`;
9. objetos obtidos através de repositories/services;
10. inferência de retorno de métodos arbitrários;
11. closures;
12. propriedades `$this->...`;
13. arrays contendo Models;
14. variáveis produzidas por `compact()`;
15. `with()` encadeado ao retorno da view;
16. componentes Blade;
17. helpers Blade;
18. null-safe operator `?->`;
19. `optional()`;
20. diretivas condicionais que tornam determinado acesso seguro;
21. política definitiva de status HTTP aceitos;
22. autenticação e autorização necessárias para executar as rotas geradas;
23. geração automática de usuários autenticados;
24. tratamento de middleware;
25. preenchimento automático de parâmetros adicionais de rota.

Esses itens devem ser adicionados conforme surgirem casos reais e testes que justifiquem o suporte.

---

# 31. Diretriz para continuar o desenvolvimento

Ao continuar este projeto:

1. leia este arquivo antes de propor alterações arquiteturais;
2. preserve os comportamentos já cobertos por testes;
3. crie testes para novos padrões antes ou junto da implementação;
4. mantenha Reflection e AST com responsabilidades claras;
5. não tente suportar todo o Laravel antecipadamente;
6. priorize um fluxo vertical funcional;
7. trate as fixtures como exemplos executáveis das capacidades do analyzer.

O foco atual continua sendo o **ControllerMethodAnalyzer**, especialmente a identificação confiável de:

```text
variável
    ↓
classe raiz
    ↓
tipo de resultado
(object ou collection)
```

para parâmetros e variáveis locais.

Somente após essa etapa estar estável deve-se avançar para a identificação das variáveis efetivamente enviadas à view e, posteriormente, para a análise do Blade.
