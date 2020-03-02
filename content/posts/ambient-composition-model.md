---
title:         "The Ambient Composition Model"
date:          2019-07-15
author:        Steven van Deursen
reviewers:     Peter Parker and Ric Slappendel
proofreaders:  Katie Tennant
tags:          [.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId: 4
draft:         false
aliases:
    - /p/acm
---

### To be able to achieve anything useful, your application code makes use of runtime data that comes in many shapes and forms. Providing access to that data can be accomplished in many ways. The way you provide object graphs with runtime data can affect the way you compose them using Dependency Injection. There are two competing models to choose from. This article describes the less common model: the Ambient Composition Model. This article is the third of a five-part series on Dependency Injection composition models.

Posts in this series:

* [DI Composition Models: A Primer](/steven/p/compositionmodels)
* [The Closure Composition Model](/steven/p/ccm)
* [The Ambient Composition Model](/steven/p/acm) (this article)
* [DI Composition Models: A Comparison](/steven/p/cmcompare)
* [In Praise of the Singleton Object Graph](/steven/p/singleton)

The goal of this article is to objectively describe the Ambient Composition Model by providing examples to highlight the difference between it and the [Closure Composition Model](/steven/p/ccm/) (CCM). In the fourth part, I’ll discuss the respective advantages and disadvantages of both models. 

Let’s continue the example of the hypothetical web shop with its `ShoppingBasketController` and `ShoppingBasketRepository`, which I introduced in the previous articles. The following example shows the construction of the `ShoppingBasketController`’s object graph once more:

{{< highlightEx csharp >}}
new ShoppingBasketController(
    new AddShoppingBasketItemHandler(
        new ShoppingBasketRepository(...)));
{{< / highlightEx >}}

Let’s assume for a moment that the web application’s basket feature requires the user’s identity---not an unusual assumption. 

Perhaps it is `AddShoppingBasketItemHandler` that requires access to the user’s identity. The following example shows the updated `ShoppingBasketController` object graph. This time, `AddShoppingBasketItemHandler` depends on an `IUserContext` abstraction, implemented by an `AspNetUserContextAdapter`:

{{< highlightEx csharp >}}
new ShoppingBasketController(
    new AddShoppingBasketItemHandler(
        {{**}}new AspNetUserContextAdapter(){{/**}},
        new ShoppingBasketRepository(...)));
{{< / highlightEx >}}

`AddShoppingBasketItemHandler`’s `Handle` method can use the supplied `IUserContext` dependency to load the current user’s shopping basket:

{{< highlightEx csharp >}}
public void Handle(AddShoppingBasketItem command)
{
    var basket = this.repository.GetBasket({{**}}this.userContext.UserName{{/**}})
        ?? new ShoppingBasket({{**}}this.userContext.UserName{{/**}});

    basket.AddItem(new ShoppingBasketItem(
        productId: command.ProductId,
        amount: command.Amount));

    this.repository.Save(basket);
}
{{< / highlightEx >}}

Inside your [Composition Root](https://mng.bz/K1qZ) you can define this ASP.NET-specific `IUserContext` adapter as follows:

{{< highlightEx csharp >}}
class AspNetUserContextAdapter : IUserContext
{
    public string UserName => HttpContext.Current.User.Identity.Name;
}
{{< / highlightEx >}}

Notice that this implementation does not require the data to be provided to the class through its constructor or a property, as you would do when applying the CCM. Instead, it makes use of the static `HttpContext.Current` property, which returns the web request’s current `HttpContext` object. By means of that `HttpContext` instance, the current username is retrieved.

The `HttpContext` instance is provided to the adapter as _ambient data_. This means that the returned data is _local_ to the current operation. In this case, the `HttpContext.Current` property "knows" in which "operation" it is running and will automatically return the correct instance for the current web request.

This stateless `AspNetUserContextAdapter` is a demonstration of the _Ambient Composition Model_ (ACM).

{{% callout DEFINITION %}}
The _Ambient Composition Model_ composes object graphs that do not store runtime data inside captured variables. Instead, runtime data is kept outside the graph and stored as ambient data. This ambient data is managed by the Composition Root and is provided to application components on request, long after those components have been constructed.
{{% /callout %}}

The following figure captures the essence of the ACM.

{{< figure src="/steven/images/compositionmodels/acmessence.svg" width="100%" alt="The essence of the Ambient Composition Model" >}}

The following sequence diagram shows the basic flow of data using the ACM.

{{< figure src="/steven/images/compositionmodels/acmflow.svg" width="100%" alt="The basic flow of the Ambient Composition Model" >}}

The previous example used ASP.NET (classic) to demonstrate the ACM. Although the implementation will be a bit different, you can use this model in a similar fashion with ASP.NET Core, as I’ll show next.

## Using the Ambient Composition Model in ASP.NET Core

When building an ASP.NET Core application, your adapter should be designed differently, but the idea is identical:

{{< highlightEx csharp >}}
class AspNetCoreUserContextAdapter : IUserContext
{
    private readonly IHttpContextAccessor accessor;

    public AspNetCoreUserContextAdapter(IHttpContextAccessor accessor)
    {
        this.accessor = accessor;
    }

    public string UseName => this.accessor.HttpContext.User.Identity.Name;
}
{{< / highlightEx >}}

In this case, the `IUserContext` implementation depends on ASP.NET Core’s [IHttpContextAccessor](https://docs.microsoft.com/en-us/dotnet/api/microsoft.aspnetcore.http.ihttpcontextaccessor) abstraction to provide access to the web request’s current `HttpContext`. ASP.NET Core uses a single instance for `IHttpContextAccessor`, which internally stores `HttpContext` as ambient data using an `AsyncLocal<T>` [field](https://github.com/aspnet/HttpAbstractions/blob/master/src/Microsoft.AspNetCore.Http/HttpContextAccessor.cs#L10). The effect of `IHttpContextAccessor` is identical to ASP.NET classic’s `HttpContext.Current`.

In both examples, runtime data is store outside the graph. This absence of a captured variable allows classes to be reused and even registered with the Singleton Lifestyle. This might even allow the adapter’s consumers (for example, `AddShoppingBasketItemHandler`) to become singletons as well.

You must be careful, though, not to let your application components directly depend on ambient data.

## Encapsulation of ambient data

Some developers might frown on the idea of using ambient data, but as long as its usage is encapsulated _inside_ the Composition Root, it is perfectly fine. A Composition Root is [not reused](https://blog.ploeh.dk/2015/01/06/composition-root-reuse/), but instead specific to one particular application, and the Composition Root knows best how data can be shared across its components.

You should, however, prevent the use of ambient state _outside_ the Composition Root, which is one reason why you would want to hide calls to .NET’s `DateTime.Now` property behind an `ITimeProvider` abstraction of some sort, as shown in the next example:

{{< highlightEx csharp >}}
class DefaultTimeProvider : ITimeProvider
{
    public DateTime Now => DateTime.Now;
}
{{< / highlightEx >}}

The `ITimeProvider` abstraction allows consuming code to become testable. Its `DefaultTimeProvider` implementation applies the ACM---the static `DateTime.Now` property provides a runtime value, while the value is never stored as a captured variable inside the class. This, again, allows the class to be stateless and immutable---two interesting properties.

Although the CCM is the prevalent model, you’ll see that most applications apply a combination of both models. At the one hand, you are likely using the CCM by capturing `DbContext` instances in repositories, while at the same time you're making use of the ACM by injecting stateless `IUserContext` or `ITimeProvider` implementations.

But instead of using the CCM to store `DbContext` instances as captured variables, as demonstrated in the previous article, you can apply the ACM, which is what I’ll demonstrate next.

## Applying the Ambient Composition Model to a DbContext

Instead of supplying a `ShoppingBasketDbContext` to the constructor of `ShoppingBasketRepository`, you can supply an `IShoppingBasketContextProvider`---much like ASP.NET Core’s `IHttpContextAccessor`---that allows the repository to retrieve the correct `DbContext`. The provider’s implementation would be responsible for ensuring that the same `DbContext` is returned for every call within the same request---but a new one for another request. This changes `ShoppingBasketRepository` to the following:

{{< highlightEx csharp >}}
public class ShoppingBasketRepository : IShoppingBasketRepository
{
    private readonly IShoppingBasketContextProvider provider;
    
    public ShoppingBasketRepository(IShoppingBasketContextProvider provider)
    {
        this.provider = provider;
    }

    public ShoppingBasket GetById(Guid id) =>
        {{**}}this.provider.Context{{/**}}.ShoppingBaskets.Find(id)
            ?? throw new KeyNotFoundException(id.ToString());
}
{{< / highlightEx >}}

`ShoppingBasketRepository` now retrieves `DbContext` from the injected `IShoppingBasketContextProvider`. The provider is queried for `DbContext` only when its `GetById` method is called, and its value is never stored inside the repository.

A simplified version of the object graph for this altered `ShoppingBasketRepository` might look like the following:

{{< highlightEx csharp >}}
new ShoppingBasketController(
    new AddShoppingBasketItemHandler(
        new AspNetUserContextAdapter(),
        new ShoppingBasketRepository(
            {{**}}new AmbientShoppingBasketContextProvider({{/**}}
                {{**}}connectionString){{/**}})));
{{< / highlightEx >}}

In this example, `ShoppingBasketRepository` is injected with `AmbientShoppingBasketContextProvider`, which in turn is supplied with a connection string. The following example shows `AmbientShoppingBasketContextProvider`‘s code.

{{< highlightEx csharp >}}
// This class will be part of your Composition Root
class AmbientShoppingBasketContextProvider : IShoppingBasketContextProvider
{
    private readonly string connectionString;
    private readonly AsyncLocal<ShoppingBasketDbContext> context;

    public AmbientShoppingBasketContextProvider(string connectionString)
    {
        this.connectionString = connectionString;
        this.context = new AsyncLocal<ShoppingBasketDbContext>();
    }

    public ShoppingBasketDbContext Context =>
        this.context.Value ?? (this.context.Value = this.CreateNew());
    
    private ShoppingBasketDbContext CreateNew() =>
        new ShoppingBasketDbContext(this.connectionString);
}
{{< / highlightEx >}}

Internally, `AmbientShoppingBasketContextProvider` makes use of .NET's `AsyncLocal<T>` to ensure creation and caching of `DbContext`. It provides a cache for a single asynchronous flow of operations (typically, within a request). In other words, `AsyncLocal<T>` stores ambient data.

`AmbientShoppingBasketContextProvider` is an adapter hiding the use of `AsyncLocal<T>` from the application, preventing this implementation detail from leaking out. From the perspective of `ShoppingBasketRepository`, it doesn’t know whether ambient state is involved or not. You could have transparently provided the repository with a “closure-esque” implementation.

This new graph for `ShoppingBasketController` uses the ACM consistently. In this case, the `DbContext` runtime data is _not_ supplied any longer during object construction, but instead, it is created on the fly when requested the first time within a given request. The Composition Root ensures that runtime data is created and cached.

## Applying the Ambient Composition Model to the user’s identity

The previous article demonstrated the CCM in the context of a queuing infrastructure. The example showed how the `OrderCancellationReportGenerator` object graph was composed while injecting runtime data through the constructor. For completeness, here’s that example again:

{{< highlightEx csharp >}}
// Composes the graph using the Closure Composition Model
IHandler<OrderCancelled> handler =
    new OrderCancellationReportGenerator(
        new OrderRepository(
            new ClosureUserContext(
                {{**}}queueContext.UserName{{/**}}), //{{annotate}}External runtime data{{/annotate}}
            new SalesDbContext(
                connectionString)));

handler.Handle(queueContext.Message); //{{annotate}}External runtime data{{/annotate}}
{{< / highlightEx >}}

Similar to the previous `AmbientShoppingBasketContextProvider`, you can create an `AmbientUserContextAdapter` implementation that replaces `ClosureUserContext` as an implementation for `IUserContext`:

{{< highlightEx csharp >}}
class AmbientUserContextAdapter : IUserContext
{
    public static readonly AsyncLocal<string> Name = new AsyncLocal<string>();

    public string UserName =>
        Name.Value ?? throw new InvalidOperationException("Not set.");
}
{{< / highlightEx >}}

As part of the Composition Root, this `AmbientUserContextAdapter` exposes an `AsyncLocal<string>` field that allows the user’s identity to be set before the graph is used. This allows the Composition Root to be written like the following:

{{< highlightEx csharp >}}
// Composes the graph using the Ambient Composition Model
IHandler<OrderCancelled> handler =
    new OrderCancellationReportGenerator(
        new OrderRepository(
            {{**}}new AmbientUserContextAdapter(){{/**}},
            new SalesDbContext(
                connectionString)));

// Set the external runtime data before invoking the composed graph
{{**}}AmbientUserContextAdapter.Name.Value = queueContext.UserName;{{/**}}

// Invoke the composed graph
handler.Handle(queueContext.Message);
{{< / highlightEx >}}

In this example, it might seem weird to have `AmbientUserContextAdapter` injected into the graph, while its ambient data is set directly after. But don’t forget that usually the construction of the graph is not done as close to initialization as shown here. The construction of such a graph is likely moved to another method, or done by the DI Container.

This completes the description of the ACM. In [the next article](/steven/p/cmcompare/), I will compare the ACM with the CCM and show why one might be preferred.

## Summary

* The Ambient Composition Model (ACM) composes object graphs that do not store runtime data inside captured variables. Instead, runtime data is kept outside the graph and stored as ambient data. This ambient data is managed by the Composition Root and is provided to application components on request, long after those components have been constructed.
* While object graphs constructed using the Closure Composition Model (CCM) are inherently stateful, object graphs that apply the ACM become stateless and immutable.
* Ambient data should be used solely _inside_ the Composition Root. Application code should be oblivious to how runtime data is acquired.
* Although the ACM is less common than the CCM, you’ll typically find that applications use both models intertwined.
* `HttpContext.Current` and `DateTime.Now` used from inside the Composition Root are common examples of the ACM. In this article, their ambient data is hidden behind `IUserContext` and `ITimeProvider` abstractions.

## Comments