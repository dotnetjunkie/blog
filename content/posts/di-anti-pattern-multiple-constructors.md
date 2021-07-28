---
title:   "Dependency Injection anti-pattern: multiple constructors"
date:    2013-06-01
author:  Steven van Deursen
reviewers: Peter Parker
tags:    [.NET general, Architecture, C#, Dependency Injection]
draft:   false
aliases:
    - /p/ctors
---

### When Dependency Injection is applied correctly and completely, it is important that each type only has one constructor—multiple constructors are redundant, make your DI configuration fragile, and lead to maintainability issues.

From a DI perspective, your applications have two kinds of types: [newables and injectables](https://web.archive.org/web/http://misko.hevery.com/2008/09/30/to-new-or-not-to-new/). Newables are classes that the application news up manually using the `new` keyword. This is true for types such as primitives, entities, [DTOs](http://en.wikipedia.org/wiki/Data_transfer_object), view models and messages. Newables contain little to no logic and application code can safely depend on their implementation; there is no need to hide them behind an abstraction.

Injectables are the types that contain the logic of our application. Injectables are usually placed behind abstractions and their consumers will depend on these [abstractions and not the implementations](https://en.wikipedia.org/wiki/Dependency_inversion_principle). This allows these types to be replaced, decorated, intercepted and mocked. When using dependency injection, injectables are configured in the start-up path of our application; the [Composition Root](https://freecontent.manning.com/dependency-injection-in-net-2nd-edition-understanding-the-composition-root/). Optionally, a DI library resolves, injects and manages the injectables for you.

{{% callout IMPORTANT %}}
Let me be clear: I don’t care how many constructors your newables have. Any number that works for you is fine with me (or at least as far as this post is concerned). What I care about is how many constructors your injectables have:
{{% /callout %}}

{{% callout %}}
**An injectable should have a single constructor.**
{{% /callout %}}

All the [volatile](https://livebook.manning.com/book/dependency-injection-principles-practices-patterns/chapter-1/section-1-3-2) dependencies that an injectable has (i.e. cannot live without) should be specified as constructor argument. This makes it easy to spot a type’s dependencies. This holds for both the person reading the code and the DI library.

{{% callout %}}
**The constructor is the definition of what dependencies a type requires.**
{{% /callout %}}

When we view the constructor as the definition of the required dependencies, what does it mean to have multiple constructors? In that situation the type has multiple definitions of what it requires, which is awkward to say the least. Violating the one-constructor convention leads to ambiguity; ambiguity leads to maintainability issues.

This alone should be reason enough to have a single constructor, but DI containers increase this ambiguity even more, by each having their own unique way of selecting the most appropriate constructor. These libraries analyze the constructor and automatically inject the dependencies into them—a process called [auto-wiring](https://livebook.manning.com/book/dependency-injection-principles-practices-patterns/chapter-12/44).

DI Container constructor resolution can be divided into three groups:

* _Group 1_: The container tries to prevent ambiguity by disallowing constructor resolution by default. If a type has multiple public constructors an exception is thrown.
* _Group 2_: The container selects the constructor with the most parameters. If this constructor contains dependencies that cannot be resolved an exception is thrown.
* _Group 3_: The container selects the constructor with the most parameters from the list of constructors where all of the parameters can be resolved by the container. When resolving a service, the container checks the configuration to see which dependencies can be resolved and selects the most appropriate constructor.

There is another difference between the various DI libraries concerning constructor selection that can lead to even more confusion. DI libraries behave differently when encountering multiple selectable constructors with the same number of parameters. Some containers will throw an exception while others will pick the ‘first’ constructor. What ‘first’ means is often undefined and therefore unreliable. A [recompile](https://blogs.msdn.microsoft.com/ericlippert/2012/05/31/past-performance-is-no-guarantee-of-future-results/) or even an application restart could result in the selection of a different constructor, as the MSDN documentation states:

> The GetConstructors method does not return constructors in a particular order, such as declaration order. Your code must not depend on the order in which constructors are returned, because that order varies. ([source](https://docs.microsoft.com/en-us/dotnet/api/system.type.getconstructors))

Letting the library pick the most suitable constructor for you based on the availability of its dependencies might sound appealing at first, but it means that a single change in your DI configuration can result in a different code path being executed at runtime. Or worse this could happen simply because the application is restarted. This flexibility makes it harder to be sure about the correctness of your application and can lead to mysterious and hard to find errors.

These reasons should be convincing enough but I repeatedly hear the same arguments for multiple constructors.

## Default constructor

Some developers define a default constructor that is called directly by the application code. This parameterless constructor in turn calls into an overloaded constructor that expects the dependencies. The default constructor creates all the dependencies and passes them on to the overloaded constructor. The overloaded constructor is called by the unit tests while the default constructor is called by the application code. For example:

{{< highlight csharp >}}
public class MoveCustomerHandler : ICommandHandler<MoveCustomerCommand>
{
    private readonly IRepository<Customer> repository;
    private readonly ILogger logger;

    public MoveCustomerHandler()
        : this(new CustomerRepository(), new FileLogger())
    {
    }

    public MoveCustomerHandler(
        IRepository<Customer> repository, ILogger logger)
    {
        if (repository == null) throw new ArgumentException();
        if (logger == null) throw new ArgumentException();
        
        this.repository = repository;
        this.logger = logger;
    }

    public void Handle(MoveCustomerCommand command)
    {
        ...
    }
}
{{< / highlight >}}

The argument is that this makes it easier to use the type (since it has a default constructor). This argument makes sense when it comes to introducing dependency injection in a legacy code base. It allows classes to be unit tested easily while allowing the legacy system to be refactored incrementally.

The downside of this approach is that the type’s dependencies are hard-wired; the [Dependency Inversion Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle) is violated. This approach makes the application inflexible since replacing, wrapping or intercepting any of the given dependencies can lead to sweeping changes throughout the application. It is a form of the [Control Freak anti-pattern](https://livebook.manning.com/book/dependency-injection-principles-practices-patterns/chapter-5/22). Control Freak may initially seem a valuable approach to adding DI into legacy applications, but when applying the Dependency Injection pattern from the beginning such default constructor is redundant.

## Optional dependencies

Another reason developers have for defining multiple constructors is to have optional dependencies. Take a look at the following code snippet:

{{< highlight csharp >}}
public class MoveCustomerHandler : ICommandHandler<MoveCustomerCommand>
{
    private readonly IRepository<Customer> repository;
    private readonly ILogger logger;

    public MoveCustomerHandler(
        IRepository<Customer> repository, ILogger logger)
        : this(repository)
    {
        if (logger == null) throw new ArgumentException();
        this.logger = logger;
    }

    public MoveCustomerHandler(IRepository<Customer> repository)
    {
        if (repository == null) throw new ArgumentException();
        this.repository = repository;
    }

    public void Handle(MoveCustomerCommand command)
    {
        if (this.logger != null)
            this.logger.Log("MoveCustomerCommand");
        ...
    }
}
{{< / highlight >}}

This anti-pattern assumes we are working with the group 3 style of container (or at least assumes the container is configured to behave this way). In the example the `ILogger` dependency is optional (since the second constructor does not need it). When there is no registration for `ILogger`, a group 3 container will skip the first constructor, and select the second constructor to inject dependencies into.

At first glance this sounds reasonable; but it isn’t because:

{{% callout %}}
**Dependencies should hardly ever be optional.**
{{% /callout %}}

If a dependency is optional, you should ask yourself whether the class should even depend on that abstraction.

An optional dependency implies that the reference to the dependency will be null when it’s not supplied. Null references complicate code because they require specific logic for the null-case. Instead of passing in a null reference, the caller could insert an implementation with no behavior, i.e. an implementation of the [Null Object Pattern](https://en.wikipedia.org/wiki/Null_Object_pattern). This ensures that dependencies are always available, the type can require those dependencies and the dreaded null checks are gone. This means we have less code to maintain and test. In the case that your application does not need to log information you simply register a `NullLogger`:

{{< highlightEx csharp >}}
public class NullLogger : ILogger //{{annotate}}Null Object pattern implementation.{{/annotate}}
{
    void ILogger.Log(LogEntry entry)
    {
        // Do nothing.
    }
}
{{< / highlightEx >}}

I know that some developers make a dependency optional and argue that they’re not interested in testing the communication between the class being tested and the dependency, but this argument raises a big red flag for me. Assuming the previous `ILogger` dependency, how can we not be interested to know whether the consumer logs details correctly or not? If we’re not interested, why is it there? Any behavior that isn’t worth testing, isn’t worth writing! If it’s not interesting then please, stop wasting your boss’s money by writing irrelevant code.

{{% callout IMPORTANT %}}
Any behavior that isn’t worth testing, isn’t worth writing!
{{% /callout %}}

The developers that use this argument are in reality keen to know the behavior works as expected and their argument is just used as an excuse to avoid writing the additional tests for each class that writes to the log. The argument is, in fact, a sign of a larger problem with the design of an application—it is an indication that the application’s code is hard to test which is often caused by violating the [SOLID principles](https://en.wikipedia.org/wiki/SOLID_%28object-oriented_design%29). Sticking with our logging example, why do all these classes log anything? Logging is a [Cross-Cutting Concern](https://en.wikipedia.org/wiki/Cross-cutting_concern) and it is better to not clutter business logic with Cross-Cutting Concerns. Cross-Cutting Concerns can be applied using [Aspect-Oriented Programming](http://en.wikipedia.org/wiki/Aspect-oriented_programming) (AOP) techniques such as using [decorators](https://en.wikipedia.org/wiki/Decorator_pattern) or interception.

{{% callout TIP %}}
This has been the main theme of my blog for the last couple of years and if you have no idea what I’m talking about please take a look at [this post](/steven/p/commands/).
{{% /callout %}}

I use these patterns to apply AOP and I find very few reasons to implement class-specific logging. My applications define a generic decorator for logging that can serialize any executed message. When an operation fails I have all necessary information available to analyze and replay operations later.

Developers tend to log too much and this is often because they are scared of losing error information. This fear is mostly unfounded. Ask yourself: “[Do I log too much?](https://stackoverflow.com/a/9915056/264697)”

## Framework types

Third-party types such as types defined by the .NET framework or NuGet packages, can be injectables that are resolved and managed by the container. Take a `SqlConnection` or Entity Framework’s `DbContext` for instance. But it is incorrect to assume that the container should auto-wire these types. Auto-wiring of third-party types can lead to maintainability and trust issues. Although third-party types are not expected to introduce breaking changes, their designers are free to add new constructors (since the [.NET Framework Design Guidelines](https://www.amazon.com/Framework-Design-Guidelines-Conventions-Libraries/dp/0321545613) do not consider adding constructors a breaking change). Your application could suddenly fail when a constructor is added to a third-party type that is auto-wired by your DI container.

{{% callout IMPORTANT %}}
Prevent using your container’s auto-wiring facility when registering third-party types.
{{% /callout %}}

A DI container uses reflection at runtime to determine the correct constructor and the addition of a new constructor may lead to the container using the new constructor. If you’re lucky the application will keep working as before or the container will throw an exception (in which case we have to change the DI configuration to use the right constructor). If you’re out of luck, the type is constructed and the application fails during its lifetime. This leads to fun late-night debugging sessions. If a user has installed a newer version of the framework (i.e. one that is different to our local installation) you won’t even be able to reproduce the issue. Nice!

Frameworks generally target a wide range of developers and rarely make their constructors DI friendly (since doing so may hinder the usability of such classes for developers that do not practice DI). On the contrary, different rules apply when it comes to design of a reusable framework. It is common for framework constructors to accept primitive values such as strings, integers, etc. Registering such framework type while relying on the container’s auto-wiring behavior can quickly lead to fragile and unreadable registrations where most (if not all) parameters are overridden with specific values. For example this is what happens when you try to auto-wire an Entity Framework `DbContext` class with Castle Windsor, for a constructor with just a single parameter:

{{< highlight csharp >}}
container.Register(
    Component.For<DbContext>()
    .ImplementedBy<DbContext>()
    .Parameters(
        Parameter.ForKey("connectionString").Eq("name=DbName")))
{{< / highlight >}}

That’s just ugly! Why are we trying to use the container’s auto-wiring facility when we’re overriding all of the parameters anyway? All frameworks allow you to register a factory delegate that enables you to control the creation in your code. It’s much better to register such factory delegate for your 3rd party injectables. With Simple Injector this looks as follows:

{{< highlight csharp >}}
container.Register<DbContext>(() => new DbContext("name=DbName"));
{{< / highlight >}}

This is much simpler, more readable, and very stable, since the C# compiler resolves the constructor during compilation.


{{% callout WARNING %}}
Don’t abandon auto-wiring for the injectables that your applications define. Their contract and dependencies tend to change regularly during development. Auto-wiring your injectables with a DI container saves you the labor of updating the Composition Root for each change you make to a constructor in your system.
{{% /callout %}}

The injectables you create are [Volatile Dependencies](https://livebook.manning.com/book/dependency-injection-principles-practices-patterns/chapter-1/section-1-3-2), because they are subject to change. Third-party injectables on the other hand are more often _Stable Dependencies_, because they exist in a production form and we expect new versions will not introduce any breaking changes (but we can expect new constructors to be added).

## Code generators

Code generators can sometimes force types to have multiple constructors. Early versions of the [T4MVC](https://github.com/T4MVC/T4MVC) for instance, had the annoying side effect of adding an extra public constructor to MVC controller types. This ambiguity would sometimes cause problems for the DI container when selecting the expected constructor. Newer versions of T4MVC resolved this issue by making the generated constructor protected.

You may not always control the code generation process or be able to change the code generator. Modifying the T4MVC template, for example, was annoying because this prevented us from updating the template from NuGet (because NuGet skips altered files). In this scenario it is better to override your container’s default constructor resolution behavior (if needed). Such a change should not affect all types that your container auto-wires.

{{% callout IMPORTANT %}}
Only change the container’s constructor resolution behavior for types that are affected by the code generator.
{{% /callout %}}

This prevents reintroducing the ambiguity that we so desperately wish to prevent.

## Summary

* Refrain from using the Bastard Injection DI anti-pattern and avoid defining optional dependencies and thereby removing the need for multiple constructors. 
* An injectable you maintain should only have one constructor. Applying this principle can prevent ambiguity which in turn can save us from having to depend on the specific constructor overload resolution behavior of your container.
* Do not use auto-wiring when dealing with framework types.
* When working with code generation, limit overriding your container’s constructor resolution behavior to the types that are affected by the code generator.

## Comments

---
#### Daniel Hilgarth - 14 June 13 
I assume that Simple Injector belongs to Group 1?

---
#### Steven - 14 June 13
Simple Injector is as far as I know the lonely member of Group 1. Besides these three groups there are btw a lot of lesser known frameworks and older framework versions that can't be placed in these three groups. Griffin Container for instance picks the smallest constructor, and older versions of Ninject pick the default constructor if available or fallback to the greediest constructor.

---
#### José Manuel - 25 June 13
Specially interesting, Steve. It has helped me to validate some of my thoughts about Dependency Injection.

Thanks for writing those great articles!
Regards,

SuperJMN

---
#### Marc Gruben - 01 September 13
Hi Steven, what about having an abstract factory as constructor parameter? This makes it much easier for unit testing (with mocking).

---
#### Steven - 02 September 13
Marc, using an abstract factory is fine, as long as you really use an abstract factory, not an Abstract [Service Locator](https://blog.ploeh.dk/2010/02/03/ServiceLocatorisanAnti-Pattern/).

