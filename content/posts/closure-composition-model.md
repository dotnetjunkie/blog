---
title:			"The Closure Composition Model"
date:			2019-07-09
author: 		Steven van Deursen
reviewers:		Peter Parker and Ric Slappendel
proofreaders:	Katie Tennant
tags:			[.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId:	2
draft:			false
aliases:
    - /p/ccm
---

### To be able to achieve anything useful, your application code makes use of runtime data that comes in many shapes and forms. Providing access to that data can be accomplished in many ways. The way you provide object graphs with runtime data can affect the way you compose them using Dependency Injection. There are two competing models to choose from. This article describes the Closure Composition Model. It is the second of a five-part series on Dependency Injection composition models.

Posts in this series:

* [DI Composition Models: A Primer](/steven/p/compositionmodels)
* [The Closure Composition Model](/steven/p/ccm) (this article)
* [The Ambient Composition Model](/steven/p/acm)
* [DI Composition Models: A Comparison](/steven/p/cmcompare)
* [In Praise of the Singleton Object Graph](/steven/p/singleton)

The goal of this article is to objectively describe the Closure Composition Model (CCM) by providing you with multiple examples, a definition, and its consequences. In the fourth part, I’ll compare the Closure Composition Model with the Ambient Composition Model, which I'll go into in the next article.

[The primer article](/steven/p/compositionmodels/) introduced a `ShoppingBasketController` for a hypothetical web shop. The next listing shows this controller again---now with a constructor, while folding its action method:

{{< highlightEx csharp >}}
public class ShoppingBasketController : Controller
{
    private readonly IHandler<AddShoppingBasketItem> handler;
    
    public ShoppingBasketController(
        IHandler<AddShoppingBasketItem> handler)
    {
        this.handler = handler;
    }

    public IActionResult AddItem(AddShoppingBasketItem viewModel) => ...
}
{{< / highlightEx >}}

The shopping application would likely contain many more classes than just this one controller. In this article, I’ll add a few more classes to the application to demonstrate the Closure Composition Model.

When it comes to supplying application components with a data-centric object, such as a `DbContext`, a common practice is to inject the object directly into the constructor of the consuming class. The next code example shows the constructor of a `ShoppingBasketRepository` class that depends on a `DbContext` derivative---the `ShoppingBasketDbContext`:

{{< highlightEx csharp >}}
public class ShoppingBasketRepository : IShoppingBasketRepository
{
    {{**}}private readonly ShoppingBasketDbContext context;{{/**}} //{{annotate}}Captured variable{{/annotate}}
    
    public ShoppingBasketRepository(
        {{**}}ShoppingBasketDbContext context{{/**}}) //{{annotate}}Constructor Injection{{/annotate}}
    {
        this.context = context;
    }

    public ShoppingBasket GetById(Guid id) =>
        this.context.ShoppingBaskets.Find(id)
            ?? throw new KeyNotFoundException(id.ToString());
}
{{< / highlightEx >}}

In this example, `ShoppingBasketDbContext` is injected into `ShoppingBasketRepository` during the repository’s construction. It stores `DbContext` internally, like it would any other injected dependency.

`DbContext` is stored in a `private` `readonly` field and will, therefore, always be available when one of the repository’s methods is invoked. The stored `DbContext` becomes a _captured variable_ that can be accessed by the class’s methods, effectively becoming a [closure](https://en.wikipedia.org/wiki/Closure(computer_programming)). I, therefore, call this model of injecting runtime data into application components during construction the _Closure Composition Model_ (CCM).

{{% callout DEFINITION %}}
The _Closure Composition Model_ composes object graphs that capture runtime data in variables of the graph’s components.
{{% /callout %}}

The following figure captures the essence of the CCM.

{{< figure src="/steven/images/compositionmodels/ccmessence.svg" width="100%" alt="The essence of the Closure Composition Model" >}}

You are likely familiar with this model on a conceptual level, as it is the prevalent practice. If you've been practicing Dependency Injection for some time, you are almost certainly acquainted with injecting `DbContext`s and other runtime values directly into constructors. This means you are applying the CCM. Perhaps you haven’t even considered there to be alternatives to this ubiquitous model. Even in [my book](https://manning.com/seemann2), you’ll find this model to be ever present.

The following sequence diagram shows the basic flow of data using the CCM.

{{< figure src="/steven/images/compositionmodels/cmmflow.svg" width="100%" alt="The basic flow of the Closure Composition Model" >}}

The complete object graph for the shopping basket feature will likely consist of many more classes. Consider the following, a not unimaginable but still reasonably shallow graph, which will serve us for the duration of this article:

{{< highlightEx csharp >}}
new ShoppingBasketController(
    new AddShoppingBasketItemHandler(
        new ShoppingBasketRepository(
            {{**}}new ShoppingBasketDbContext({{/**}} //{{annotate}}Injecting runtime data{{/annotate}}
                connectionString))));
{{< / highlightEx >}}

In this graph, `ShoppingBasketDbContext` is injected directly into `ShoppingBasketRepository`, becoming a captured variable in the repository’s closure. Since `DbContext` instances contain request-specific data and are not thread-safe, each request should get its own `DbContext` instance. This implies that the consuming `ShoppingBasketRepository` should not be reused across requests---even if it contains no state of its own. `ShoppingBasketRepository` should not outlive the lifetime of a single web request.

Letting `ShoppingBasketRepository` have a Singleton Lifestyle would cause `DbContext` to be kept alive for the application’s lifetime. This is dreadful because that would cause it to be used by multiple requests simultaneously---a horrible prospect. Again: `DbContext`s are not thread-safe.

{{% sidebar "The Singleton Lifestyle" %}}
In the context of Dependency Injection, a _Lifestyle_ is a formalized way of describing the intended lifetime of a dependency. One of those formalized lifestyles is the _[Singleton Lifestyle](https://mng.bz/qXJw)_. When a component is configured/declared using the Singleton Lifestyle, it means that there will be only one instance of that component, and that instance is perpetually reused. The Singleton Lifestyle should _not_ be confused with the [Singleton design pattern](https://en.wikipedia.org/wiki/Singleton_pattern). They both guarantee the existence of just one instance, but their similarity ends there.
{{% /sidebar %}}

`ShoppingBasketRepository` shouldn’t be a singleton, and the same is true of its consumer---`AddShoppingBasketItemHandler`---for exactly the same reason; reusing the service would cause the repository to be reused, which again would cause `DbContext` to be reused. A pattern seems to emerge…

## The closure’s lifetime restriction

This restriction on the consumer’s lifetime is transitive, meaning that it affects all the dependency’s direct and indirect consumers. It bubbles up the object graph all the way to the top-most object in the graph---`ShoppingBasketController`, in the example. Not adhering to this restriction causes a problem called [Captive Dependencies](https://blog.ploeh.dk/2014/06/02/captive-dependency/). The book defines it as follows:

{{% callout DEFINITION %}}
A _Captive Dependency_ is a dependency that’s inadvertently kept alive for too long because its consumer was given a lifetime that exceeds the dependency’s expected lifetime. [§8.4.1]
{{% /callout %}}

In the previous example, `DbContext` is supplied to the object graph during construction---an example of the CCM. The CCM infers that even stateless components should _not_ be kept alive for the application's lifetime, as it would keep their stateful dependencies alive.

## Providing a closure graph with external runtime data

When you’re using a DI Container to compose your application’s object graphs, a `DbContext` can be easily injected into a class’s constructor. That’s because a `DbContext` itself does not depend on externally provided runtime data. The domain objects it maintains are created by the `DbContext` itself. Although it depends on a connection string, that string won’t change during the lifetime of the application, making it a (fixed) configuration value rather than a runtime value. Registering the `DbContext` using such a fixed value is rather straightforward, as shown in the following example, which uses [Simple Injector](https://simpleinjector.org):

{{< highlightEx csharp >}}
string connectionString = LoadConnectionStringFromConfig();

container.Register(
    {{**}}() => new ShoppingBasketDbContext({{/**}} //{{annotate}}Runtime data{{/annotate}}
        {{**}}connectionString{{/**}}), //{{annotate}}Configuration value{{/annotate}}
    Lifestyle.Scoped);
{{< / highlightEx >}}

`ShoppingBasketDbContext` is created by the lambda, rather than being supplied from the outside. When the graph requires externally provided runtime data, however, the previous registration will not work.

Say, for instance, you need to process messages from a queue, but the handling code needs to run in the context of the user on whose behalf the message was published. In that case, the user’s identity is possibly provided to you by message infrastructure. When you build the object graph by hand (a.k.a. [Pure DI](https://blog.ploeh.dk/2014/06/10/pure-di/)), instead of using a DI Container, that identity can easily be provided to the graph as follows:

{{< highlightEx csharp >}}
 IHandler<OrderCancelled> handler =
    new OrderCancellationReportGenerator(
        new OrderRepository(
            new ClosureUserContext(
                {{**}}queueContext.UserName{{/**}}), //{{annotate}}External runtime data{{/annotate}}
            new SalesDbContext(
                connectionString)));

handler.Handle(queueContext.Message); //{{annotate}}External runtime data{{/annotate}}
{{< / highlightEx >}}

Both the user’s identity and the message are externally provided runtime values. But while the message is passed along the graph’s public API---in this case the `IHandler<T>.Handle` method---the user’s identity is an implementation detail, applied to the graph during construction.

In this case, `OrderRepository` depends on the `IUserContext` abstraction, which is implemented by the `ClosureUserContext` class. `ClosureUserContext` can be as trivial as the following:

{{< highlightEx csharp >}}
class ClosureUserContext : IUserContext
{
    public ClosureUserContext(string userName)
    {
        this.UserName = userName;
    }

    public string UserName { get; }
}
{{< / highlightEx >}}

When practicing Pure DI, it is relatively easy to provide an object graph with runtime data, as the previous two examples showed. When dealing with DI Containers, on the other hand, it can be harder to provide deeper parts of the graph with such externally provided data. In that case, you can choose to initialize the object graph after construction by feeding it with runtime data, for instance, using Property Injection. In the context of the queuing example, it would mean making a change to the `ClosureUserContext` implementation, by making it mutable instead:

{{< highlightEx csharp >}}
class ClosureUserContext : IUserContext
{
    public string UserName { get; set; } //{{annotate}}Writable property{{/annotate}}
}
{{< / highlightEx >}}

The following example demonstrates how to use this new ClosureUserContext using [Autofac](https://autofac.org), although the solution would be similar regardless of the chosen DI Container:

{{< highlightEx csharp >}}
using (ILifetimeScope scope = container.BeginLifetimeScope())
{
    var userContext = scope.Resolve<ClosureUserContext>();
    userContext.UserName = queueContext.UserName;

    // Let Autofac compose the object graph which consists of ClosureUserContext
    var handler = scope.Resolve<IHandler<OrderCancelled>>();

    // Invoking the constructed and initialized graph
    handler.Handle(queueContext.Message);
}
{{< / highlightEx >}}

In this example, you start by creating an Autofac lifetime scope. A lifetime scope provides a cache for _scoped_ instances. A scoped instance is cached and reused within a single lifetime scope. Configuring `ClosureUserContext` as scoped allows you to request the scope’s single `ClosureUserContext` instance and initialize it with the user’s identity. Because that user context is registered as scoped, that same instance will be injected into the handler’s graph.

{{% sidebar "The Scoped Lifestyle" %}}
Similar to the Singleton Lifestyle, the Scoped Lifestyle is a formalized way of describing the intended lifetime of a dependency. Scoped dependencies behave much like Singleton dependencies, but within a single, well-defined scope. Scoped dependencies aren’t shared across scopes. Each scope has its own cache of associated dependencies.
{{% /sidebar %}}

For completeness, here are the required Autofac registrations to compose the discussed graph:

{{< highlightEx csharp >}}
builder.RegisterType<OrderCancellationReportGenerator>()
    .As<IHandler<OrderCancelled>>();

builder.RegisterType<OrderRepository>()
    .As<IOrderRepository>();

{{**}}builder.RegisterType<ClosureUserContext>(){{/**}}
{{**}}    .As<ClosureUserContext>(){{/**}}
{{**}}    .As<IUserContext>(){{/**}}
{{**}}    .InstancePerLifetimeScope();{{/**}}

builder.Register(c => new SalesDbContext(connectionString))
    .InstancePerLifetimeScope();
{{< / highlightEx >}}

Although the username is not supplied to the constructor, this initialization is still part of the object graph’s construction phase. It’s only after the graph is fully constructed and initialized that it is invoked---in the example, the call to `handler.Handle`.

Just as before in the previous example, _runtime data_ became a _captured variable_---in this case, the username. This data was accessed by `ClosureUserContext`'s methods. In other words, this is another example of the CCM.

Of the two DI composition models, the CCM is the best known and most used. Because of its prevalence, it’s easy to overlook the other existing model, which brings me to the lesser-known and somewhat competing model that you can use to compose object graphs: the Ambient Context Model, which I will discuss in [the next article](/steven/p/acm/).

## Summary

* The _Closure Composition Model_ composes object graphs that capture runtime data in variables of the graph’s components.
* With the Closure Composition Model, you keep this data alive as long as the consuming component.
* Of the two composition models, the Closure Composition Model is the most commonly used and best known.
* One of the most prominent consequences of the Closure Composition Model is that you need to take care not to introduce Captive Dependencies.
* A _Captive Dependency_ is a dependency that’s inadvertently kept alive for too long because its consumer was given a lifetime that exceeds the dependency’s expected lifetime.

## Comments