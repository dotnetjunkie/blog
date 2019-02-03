---
title:   "Abstract Factories are a Code Smell"
date:    2016-08-10
author:  Steven van Deursen
tags:    [Dependency Injection, OOP, Software Design]
draft:   false
id:		 100
aliases:
    - /p/abstract-factories
---

### When it comes to writing LOB applications, abstract factories are a code smell as they increase the complexity of the consumer instead of reducing it. This article describes why and offers alternatives.

An [Abstract Factory](https://en.wikipedia.org/wiki/Abstract_factory_pattern) decouples the creation of a family of objects from usage. Compared to injecting a service into a constructor, a factory allow objects to be created lazily, instead of up front during object graph composition. Many applications make use of the Abstract Factory pattern extensively to create all sorts of objects. When Abstract Factories are used to return application services, however, application complexity starts to increase. When developing Line of Business applications (LOB), the usefulness of this type of Abstract Factory is limited and should in general be prevented.

**Note:** _This article specifically targets factory abstractions that return application *service abstractions* and are consumed by application components; any other type of factory is fine and out of the context of this article. The kind of factory that you should reconsider is the factory abstraction that builds and returns application services._

Here’s a simple example of such a problematic Abstract Factory:

{{< highlight csharp >}}
public interface IServiceFactory
{
    IService Create();
}
{{< / highlight >}}

When you consider the consumer of such factory, a factory is hardly ever the right abstraction. Instead of lowering complexity for the consumer, a factory increases complexity, because instead of having just a dependency on the service abstraction `IService`, the consumer now requires a dependency on both `IService` and the Abstract Factory `IServiceFactory`. Although some find this increase to be insignificant, the increase in complexity can be felt instantly when unit testing such classes. Not only does this force you to test the interaction the consumer has with the service, you have to test the interaction with the factory as well.

Generally, the use of a factory abstraction is not a design that considers its consumers. According to the [Dependency Injection Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle) (DIP), abstractions should be defined by their clients, and since a factory increases the number of dependencies a client is forced to depend upon, the abstraction is clearly not created in favor of the client and we can therefore consider this to be in violation of the DIP.

To generalize this even more, we can state

> Service abstractions should not expose other service abstractions in their definition

This means that a service abstraction should not accept other service types as input, nor should it have service abstractions as output parameters or as a return type. Application services that depend on other application services force their clients to know about both abstractions. The problem is therefore broader than just factories, but for the rest of the article I’ll solely focus on the Abstract Factory.

Instead of having an `IServiceFactory` abstraction returning `IService`, at least two alternatives exist:

* Define a different abstraction (a [façade](https://en.wikipedia.org/wiki/Facade_pattern) or [adapter](https://en.wikipedia.org/wiki/Adapter_pattern)) that forwards the call to the service and returns the result that the service produces.
* Let the consumer use the service abstraction directly, and have a special implementation (such as a [composite](https://en.wikipedia.org/wiki/Composite_pattern), [mediator](https://en.wikipedia.org/wiki/Mediator_pattern) or [proxy](https://en.wikipedia.org/wiki/Proxy_pattern)) that forwards the call to the real implementation.

## Defining an adapter

Take for instance the following class:

{{< highlight csharp >}}
public sealed class ShipmentController
{
    private readonly ICommandHandlerFactory factory;
    
    public ShipmentController(ICommandHandlerFactory factory)
    {
        this.factory = factory ?? throw new ArgumentNullException("factory");
    }

    public void ShipOrder(ShipOrder cmd)
    {
        ICommandHandler<ShipOrder> handler = factory.Create<ShipOrder>();
		
        handler.Handle(cmd);
    }
}
{{< / highlight >}}

The code snippet shows the `ShipmentController` class that depends on the `ICommandHandlerFactory`, which is used to create an `ICommandHandler<ShipOrder>`. The returned handler is invoked by calling its `Handle` method to execute the command. In other words, `ShipmentController` depends on both `ICommandHandlerFactory` and `ICommandHandler<T>`. Compare that to the alternative design using an adapter:

{{< highlight csharp >}}
public sealed class ShipmentController
{
    // Constructor argument. Removed the constructor for brevity.
    private readonly ICommandDispatcher dispatcher;

    public void ShipOrder(ShipOrder cmd) => this.dispatcher.Dispatch(cmd);
}
{{< / highlight >}}

By creating the `ICommandDispatcher` abstraction , you effectively halved the complexity of the controller. In this case the complexity is reduced to the point that you probably could consider removing controllers altogether and replacing them with a single dispatcher (or Front Controller). But that’s a story for another day.

Inside your [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/) you can create an implementation of `ICommandDispatcher` that forwards commands to a created command handler. When using a DI container, your `CommandDispatcher` could look as follows:

{{< highlight csharp >}}
// Part of your Composition Root.
sealed class CommandDispatcher : ICommandDispatcher
{
    private readonly Container container;

    public void Dispatch(dynamic cmd) => GetHandler(cmd.GetType()).Handle(cmd);

    private dynamic GetHandler(Type type) =>
        container.GetInstance(typeof(ICommandHandler<>).MakeGenericType(type));
}
{{< / highlight >}}

## Defining a Proxy

Instead of introducing a new abstraction such as the previous `ICommandDispatcher`, you might as well let the consumer depend on the service abstraction directly. This is useful in cases where no (extra) runtime data is required to make the decision which component to dispatch to. Imagine a scenario where your `ShipmentController` must be a long-lived object, while command handlers themselves must have a shorter lifetime. In this scenario you can't inject the `ShipOrderCommandHandler` implementation directly into the controller because that would cause the handler to become a [Captive Dependency](http://blog.ploeh.dk/2014/06/02/captive-dependency/). Instead of injecting a factory, however, you are better off creating a proxy implementation that delays the creation of the command handler until the moment its `Handle` method is called. Your `ShipmentController` becomes the following:

{{< highlight csharp >}}
public sealed class ShipmentController
{
    private readonly ICommandHandler<ShipOrder> handler;

    public void ShipOrder(ShipOrder command) => this.handler.Handle(command);
}
{{< / highlight >}}

You define a proxy that wraps the original instance while allowing it creation to be delayed:

{{< highlight csharp >}}
// Part of your Composition Root.
sealed class DelayedCommandHandlerProxy<T> : ICommandHandler<T>
{
    private readonly Func<ICommandHandler<T>> handlerFactory;

    public void Handle(T command) => this.handlerFactory().Handle(command);
}
{{< / highlight >}}

You probably already noticed the `handlerFactory` dependency. Whether or not you defined a proper abstraction like `ICommandHandlerFactory` or simply use a `Func<T>` is irrelevant to the question whether it is a factory—this is a factory. But note that `DelayedCommandHandlerProxy<T>` is an infrastructural component, located in your Composition Root. Because the Composition Root has intrinsic knowledge about building the object graphs (it can be considered to be a big factory itself), it is perfectly fine to depend on this `Func<T>` here, just as the previous `CommandDispatcher` depends on your DI container.

Without the use of a DI container, the construction of our `ShipmentController` using this proxy would become something similar to the following:

{{< highlight csharp >}}
new ShipmentController(
    new DelayedCommandHandlerProxy<ShipOrder>(
        () => new ShipOrderCommandHandler()));
{{< / highlight >}}

Here you inject a lambda expression into the proxy class that effectively delays the creation of the real handler. When your Composition Root is structured correctly, it is usually trivial to add such proxy.

Using your favorite DI container, registering the proxy would be as easy as:

{{< highlight csharp >}}
container.RegisterDecorator(
    typeof(ICommandHandler<>),
    typeof(DelayedCommandHandlerProxy<>),
    Lifestyle.Singleton);
{{< / highlight >}}

The previous code snippet shows the registration API of [Simple Injector](https://simpleinjector.org). Where Simple Injector is concerned, your proxy class is a [Decorator](https://en.wikipedia.org/wiki/Decorator_pattern). Simple Injector’s decorator sub system has special support for handling `Func<T>` dependencies that produce the decoratee. It handles this natively and the code that is compiled by Simple Injector for this object graph will be identical to the previous manually constructed object graph.

Using the proxy, the consumer was oblivious to the implementation detail of this delayed creation, which is what you should strive for. In other words, the use of a proxy allows keeping this and other consumers as simple as possible by moving the responsibility of object creation into the Composition Root. This prevents you from having to make sweeping changes throughout the code base, as the proxy can be added transparently.

The dispatchers, facades, adapters, and proxies you define might still create objects themselves—they might still behave as factories internally and this is perfectly fine. The factory-like behavior of these infrastructural components is an implementation detail encapsulated in the Composition Root, and instead of exposing instances through their contract, those components act on the given service and return their result. (Do note that both void and exceptions can be considered results as well.)

You obviously still need to have this factory-like behavior; removing the factory abstractions from your application does not immediately remove the need to create components. But like all code that creates components, this code should be part of your Composition Root.

## Using Abstract Factories for Delayed Object Creation

There are obviously cases where the delayed creation of services makes sense, the prevention of Captive Dependencies being one of them, and in case you dispatch to a wide range of implementations it’s sometimes not very practical to build them up front, especially when such proxy can forward the call to a wide range of underlying implementations. Creating those dependencies all of the time won’t be a problem when there are just a dozen, but when the number of dependencies to dispatch to becomes big (as you can easily experience when dispatching commands), things start to change.

Although the previous reasons are valid, there are at least as many *invalid* reasons for delaying the creation of services as there are valid reasons like those you have just seen.

One such invalid reason for delayed creation is when services require runtime data during construction. Application components should not require runtime data during initialization. This is a code smell and this is something I [discussed before](/steven/p/runtime-data).

Another *invalid* reason for delaying creation is when components require heavy initialization. Heavy initialization however is an implementation detail. Introducing a factory because some component is costly to create means the implementation detail is leaking into the consumer, and doing so would violate the DIP. Introducing a factory to delay the creation of an existing component, leading to needless refactoring of the application. This is a violation of the [Open/Closed Principle](https://en.wikipedia.org/wiki/Open/closed_principle), which states that you must be able to make changes without having to do [shotgun surgery](https://en.wikipedia.org/wiki/Shotgun_surgery).

You could avoid sweeping changes by injecting factories for all your service up front. Although this would work, doing so would obviously be madness. It would make the application very hard to maintain and test. Other developers will hate you for doing this and they are right, because implementation details should not leak through abstractions.

Instead you should wrap the component with a proxy class that is able to delay the creation, as you’ve seen previously:

{{< highlight csharp >}}
// Part of your Composition Root.
sealed class LazyServiceProxy : IService
{
    private readonly Lazy<IService> lazyService;

    public void DoSomething() => this.lazyService.Value.DoSomething();
}
{{< / highlight >}}

An even more compelling argument against creating a factory for expensive components is that those components shouldn’t exist in the first place. Constructors of your components should do nothing more than storing the incoming dependencies. In other words, [injection constructors should be simple and fast](http://blog.ploeh.dk/2011/03/03/InjectionConstructorsshouldbesimple/). This diminishes the need for delayed creation.

Sometimes, however, you are dealing with third-party components that require heavy initialization. You obviously can’t change those components, but at the same time, application code should not depend on third-party components or their abstractions directly. Doing so is, again, a violation of the DIP, because those third-party components/abstractions are not defined by your application. You should, instead, define application-specific abstractions that hide the existence of these components from the application. To connect our application to a third-party component an adapter should be created as part of your Composition Root that forwards or translates calls from the abstraction to the third-party component. With this practice, it becomes trivial to solve the problem of such expensive third-party component—refactoring to deal with heavy initialization is then simply a matter of changing the adapter and you’re done. Again, no application code needs to be harmed.

## Using Abstract Factories for Lifetime Management

Yet another common reason why developers invalidly add factories is to allow application code to explicitly manage the lifetime of a component. They introduce a factory abstraction that is in control of the creation of a component and passes on the ownership and management of the created component to the caller. The caller becomes responsible of ending the component’s lifetime; which is usually ended by calling `Dispose`.

Application code, however, should not be responsible for the management of the lifetime of objects. Putting this responsibility inside the application code means you increase complexity of that particular class and make it more complicated to test and maintain. You’ll often see this lifetime management logic get duplicated across the application, instead of being centralized, which is what you should be aiming for.

Clients will obviously only be able to dispose of a factory-created service when that service abstraction implements `IDisposable`. Implementing `IDisposable` on abstractions however is—you might have guessed it—a [DIP violation](https://stackoverflow.com/a/2635733/264697). It’s always easy to come up with an example of an implementation of such service that doesn’t require deterministic disposal. If you can come up with an implementation that doesn’t require disposal it means your abstraction is defined with a specific implementation in mind, hence a DIP violation.

For instance, such a component can be wrapped with a decorator that implements some cross-cutting concern, e.g. logging. It’s easy to imagine a logging decorator implementation that doesn’t require resource clean-up. Neither should it forward the `Dispose` call to the wrapped component, because the decorator can’t reasonably know if it’s allowed to dispose of its decorated dependency, since that dependency might be intended to outlive the decorator. Disposing of the dependency will lead to problems when the dependency is a longer-lived component or when it becomes in some future point in time, becasue the decorator would dispose a component that the application still intends to use. This would inevitably lead to an `ObjectDisposedException`.

Removing the `IDisposable` interface from a service abstraction (and moving it to the implementation) means a consumer can’t be responsible for managing the lifetime of the object. YOu elevate this responsibility back to the Composition Root. A common way for the Composition Root to manage the lifetime of such a dependency is by defining it as ‘scoped’, where the scope can be both explicitly or implicitly defined.

Scopes are often defined implicitly when working in web applications. Most DI containers wrap the web request inside such a scope and this ensures your dependencies will be disposed of at the end of each web request. Other DI containers or other application types however expect you to explicitly wrap the request with a scope.

Sometimes, however, you need the scope to be more fine-grained and this requires you to manage the lifestyles explicitly. But instead of doing this in application code, you want to do this management in an infrastructural component that is part of your Composition Root. The following example shows a proxy class that wraps the resolution of a service into an explicitly defined scope:

{{< highlight csharp >}}
// Part of your Composition Root.
sealed class ScopedCommandHandlerProxy<T> : ICommandHandler<T>
{
    private readonly Container container;
    private readonly Func<ICommandHandler<T>> handlerFactory;

    public void Handle(T command)
    {
        using (ThreadScopedLifestyle.BeginScope(this.container))
        {
            ICommandHandler<T> handler = this.handlerFactory();
            handler.Handle(command);
        }
    }
}
{{< / highlight >}}

If the service (in this case the command handler) depends on any component that has a scoped lifestyle, the previous using block will ensure that the lifetime of that scoped component will end when the using block ends. This allows managing the lifestyle of your components in a very fine-grained manner, without having to complicate application code while maintaining maximum flexibility.

## Abstract Factories in Frameworks

This article specifically targets LOB applications, where the discussed factory abstractions often make little sense. While designing frameworks, however, defining abstract factory abstractions often makes a lot of sense, because frameworks allow application developers to intercept the creation of the framework’s main types. Still, you won’t see any application code take a dependency on such framework-defined Abstract Factory. You will typically see the factory abstraction being overridden inside the Composition Root, while application code stays oblivious of this. In this case the framework is the consumer of the Abstract Factory; your Composition Root merely implements it.

As a last note I would like to stress that this article targets Abstract Factories that return application services. Abstract factories can be used for the creation of lots of other types of objects, such as data centered objects (such as DTOs or Unit of Work objects) or resources such as database connections. These types of factories are very useful, because they don’t expose other application abstractions from their definition.

## Conclusion

When it comes to writing LOB applications, abstract factories are a code smell, because they increase the complexity of the consumer instead of reducing it. You are better off either replacing the factory abstraction with an adapter or proxy, because this avoids increasing the complexity of the consumer. Proxies are especially great because they prevent having to make sweeping changes later in the development process.

A more elaborate discussion of this topic can be found in section 6.2 of [my book](https://manning.com/seemann2).

## Comments

---
#### Laksh - 23 August 16

So based on your reply [here](https://stackoverflow.com/questions/39048122) I was looking at 'façade' option since I have to get service instance based on runtime value.

Inside composition root, the `CommandDispatcher` implementation has reference to IOC container. But we also have to register `CommandDispatcher` with IOC container so that it can inject dispatcher into controller. So its like `CommandDispatcher` is referencing container and container also has reference to `CommandDispatcher`. Is this okay?

---
#### Steven - 24 August 16

Hi Laksh,

It is perfectly fine to reference the container from anywhere within your Composition Root. Your CommandDispatcher should be part of your Composition Root and in that case you're okay.

---

#### Gica Galbenu - 30 August 16

Hi Steven,
I'm trying to use a __strict__ layered architecture with UI, API, Domain and Infrastructure for a PHP Web Application. So, UI uses API to acces Domain data and must have no access to any entity or domain service. In my architecture API is an anti-corruption layer.

This being the case, how should Entity objects be available on API?

API is injected in UI by some container; should entities be injected in API as well? What about entities, API services or Domain services that must _create_ other entities, should I apply the Prototype Pattern and clone an injected entity prototype?

P.S. I'm a big fan of yours. Thanks for your blogs! Keep helping us, please! :)

---
#### Steven - 30 August 16

Hi Gica Galbenu,

I have no experience with PHP whatsoever so I can't really comment on this. In general however, entities should not be injected; entities are data, returned by method calls on domain services. Neither should domain entities be exposed through your API; you expose simple DTOs through your API and keep your domain entities internal.

---
#### Sia - 16 September 16

Excellent articles! Thank you! I wish I could work for you, that will be a huge learning opportunity! :-)

---
#### Felix - 27 October 16

Hi Steven,

the composite root is typically the entry point of the application, and often as a solution grows, more composite roots get added. For example, I may have a Web Api composite root, a message queue Worker composite root and a web socket composite root.

All your suggestions revolve around moving knowledge of implementation details to the composite root, which makes perfect sense as the composite root by nature already has intimate knowledge of the entire application -- with the exception of other composite roots.

So that wrinkle raises a new question: What is your recommendation for re-using your suggestions across multiple composite roots that are independent of each other?

The only thing I could think of is a new project that has intimate knowledge of the entire application ( minus the composite roots ) that gets re-used by the composite roots? But that then goes against your advice of placing them in the composite root. Curious to hear your thoughts.

Thank you, very helpful article!

---
#### Steven - 27 October 16

Hi Felix,

Typically, Composition Roots are not reused, as explained by Mark Seemann and me [here](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/).

You will typically only have reuse in case you have multiple applications that share a lot of the exact same functionality (e.g. both an MVC application and Web API that allow clients to execute the same use cases). In that case you will extract duplicate parts of your Composition Roots into a shared location. This shared code however is still part of your Composition Root, so my advice still holds: you place this in the Composition Root.

---
#### Dan - 14 July 17

Quick question. I use a factory to create instances of a specific `DbContext` because when we do validation and want to run multiple rules at the same time multithreaded, we can't run those at the same time with a single `DbContext` instance. Thus, I have to create multiple instances. Is that okay?

---
#### Steven - 14 July 17

Hi Dan,

As the beginning of the article states, this article specially targets "factory abstractions that return application service abstractions and are consumed by application components". A `DbContext` is not an application component; it is a bag of runtime data. This means that this article does not apply to a factory that returns a `DbContext`.

Whether it is okay to have a factory for DbContexts however is a different, and perhaps more difficult to answer question. It certainly isn't my first preference, as you can read in [this Stackoverflow answer](https://stackoverflow.com/a/10588594/264697). A factory implies a new instance is returned on every call, which means application code becomes responsible of managing the `DbContext`, which might not always be the best solution.

A more reasonable design (IMO) would to have a provider (where the `CreateContext()` method is replaced with a `GetContext()` method or `CurrentContext` property), that allows access to an existing `DbContext`, where that `DbContext` is reused within a certain container.

---
#### Dan - 14 July 17

That makes the assumption that the command isn't doing anything async on multiple threads at the same time. If you do this and want to run validation rules or the command needs to load data from totally unrelated tables to complete itself, you'd be forced to wait if you were in the context of only one DbContext per command. Also, not every command is going to need a DbContext, so you'd be running code that never does anything for some commands (i.e. SaveChanges would be 0), but you'd be taking up possible resources creating it. The command really should be responsible for dealing with the database logic itself. If you need to connect to multiple databases across the application, then your decorator solution gets convoluted and running code that ends up never used.

I do understand what you are saying about the Method injection being a possible issue. No one likes passing the context around. That being said, you can always make the Factory have either CreateNew or GetCurrent (which calls New if one isn't there) and make the factory per web request or whatever. Then its up to each individual class to determine if it should be joining the command's context or it should use its own. Does that seem okay?

---
#### Steven - 15 July 17

Hi Dan,

I must disagree on almost all your points here. What I am proposing works fine when doing async or multi-threaded code, doesn't require a `DbContext` to be created and managed when it isn't used, and I personally never saw my decorators got convoluted because of this. But even if decorators get convoluted, that's still better than spreading and duplicating this kind of logic throughout the application.

That said, the `GetCurrent` method on the Factory you are talking about is what I call a Provider. Where a factory always creates a new one, a Provider provides you with an existing instance (where it might create it on the fly when it is requested for the first time). The application can just call `provider.GetInstance()` and it is up to the infrastructure to decide whether or not a new instance is created or not. Typically this means that the infrastructure manages the Scope in which code runs. For instance, when you wish to run validations of a command in parallel, this is something you can manage in the decorator, where the creation of each validator is done on its own thread, where the resolve is wrapped in its own scope. The results can be merged back to a single result.

If you are having trouble to figure out how to do this with Simple Injector while using the command/handler and query/handlers designs that I talked about in the past on this blog, feel free to post a question to [this Github repository](https://github.com/dotnetjunkie/solidservices/issues/new). This is the place to have these kinds of architectural discussions. Github makes it much easier to show some code. Without discussing some actual code, chances are high that we are simply talking about different things and don't understand each other.

---
#### Dan - 17 July 17

No, I understand the whole decorator thing and am already using/loving it. I'd rather not create a bunch of classes for each individual validation as that could get greatly tedious if you have dozens of them. Each individual validation that needs database access can't join the current context. If they did, it would crash. They need their own separate instance of DbContext since it is all running multi-threaded. I'm guessing you are proposing any validations that have to access the database need to be their own class so they get their own instance of DbContext? I suppose you could do that, but I'd rather have my validation of a command all together if possible, though my decorator does allow multile IValidators.

I was just saying that you don't want to manage DbContext in a decorator itself since there is no guarantee a command would use it or even create a TransactionScope or something. The command itself should deal with that since it knows what is going to happen.

Wouldn't it be better to just have the command take your IDbContext or whatever that is Scoped and then any supporting classes that work with the "Current" would inject that same interface while all the others would inject the Factory to create a new one?
