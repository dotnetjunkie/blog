---
title:			"DI Composition Models: A Comparison"
date:			2019-07-25
author: 		Steven van Deursen
reviewers:		Peter Parker and Ric Slappendel
proofreaders:	Katie Tennant
tags:			[.NET General, Architecture, C#, Dependency Injection]
gitHubIssueId:	5
draft:			false
aliases:
    - /p/cmcompare
---

### To be able to achieve anything useful, your application code makes use of runtime data that comes in many shapes and forms. Providing access to that data can be accomplished in many ways. The way you provide object graphs with runtime data can affect the way you compose them using Dependency Injection. There are two competing models to choose from. This article compares those two models. It is the fourth of a five-part series on Dependency Injection composition models.

In the previous articles, I introduced the two available composition models that you can use to supply DI-composed object graphs with runtime data:

* The [Closure Composition Model](/steven/p/ccm) (CCM) allows you to compose object graphs that capture runtime data in variables of the graph’s components.
* The [Ambient Composition Model](/steven/p/acm) (ACM) allows you to compose object graphs that are stateless and immutable. You keep runtime data outside the graph and store it as ambient data—ready to be pulled in on demand.

If you take a step back and look at the object graphs your current application is composed of, you'll likely find that you are using both models. Most of the object graphs in your system have stored contextual or internal runtime data inside captured variables somewhere. Unit-of-Work-like state bags such as Entity Framework’s `DbContext` are typically stored inside private fields of a class. These graphs apply the CCM. On the other hand, other parts of a graph might be completely stateless and immutable, where data is retrieved from `HttpContext.Current` or other ambient constructs, thus applying the ACM.

{{< figure src="/steven/images/compositionmodels/exampleobjectgraph.svg" width="100%" alt="An example of a simple object graph" caption="An example of a simple object graph" >}}

The ACM is perhaps less known, but not less interesting, and there are some downsides to consider for both models. So it’s not always obvious which one to use. In the remainder of this article, I will compare both composition models. I’ll start by elaborating the possible technical limitations imposed by the environment in which you build and run your application.

## Technical limitations imposed by the environment

I should start with the elephant in the room: there is little reason to try to compare the two composition models when one is technically infeasible in the environment you’re working in. I’ll give an example for each model.

With the introduction of the new asynchronous programming model in .NET 4.0, you can now write code that doesn’t block threads but instead uses the more-efficient I/O completion ports. With this model, it is no longer possible to link ambient data to a specific thread—a single request flows (sequentially) from thread to thread. Instead, different constructs should be used, such as [`AsyncLocal<T>`](https://docs.microsoft.com/en-us/dotnet/api/system.threading.asynclocal-1) and [`CallContext`](https://docs.microsoft.com/en-us/dotnet/api/system.runtime.remoting.messaging.callcontext). They represent “ambient data that is local to a given asynchronous control flow, such as an asynchronous method.” `AsyncLocal<T>`, however, was added to .NET 4.6, while `CallContext` is only available in the full .NET version, and its semantics changed in .NET 4.5 to make it usable in combination with async/await.

In the good old days, when working with the asynchronous programming model in old environments such as .NET 4.0 and Silverlight, there were issues regarding the use of ambient data.

Such limitations could as well hold outside the .NET ecosystem. I could imagine that environments such as C++ or PHP would have the same limitations concerning ambient data, although, admittedly, I have little experience with those environments.

With the CCM, on the other hand, the statefulness of the objects forces you to create new object graphs on every request. When working in environments with very tight memory constraints, you should consider moving to the ACM: it allows the reuse of complete object graphs, as they are stateless anyway.

This means that neither model is inherently better than the other. The constraints of the target environment can play a determining role in which model is most suited. There are areas, however, where the CCM outperforms its sibling. This is when we look through the lens of Temporal Coupling.

## Temporal Coupling

One prominent advantage of the CCM is that it can guarantee the availability of runtime data by supplying that data through its constructor. Any initialization of a component that is done outside the constructor leads to the [Temporal Coupling](https://blog.ploeh.dk/2011/05/24/DesignSmellTemporalCoupling/) design smell.

{{% callout DEFINITION %}}
_Temporal Coupling_ occurs when there’s an implicit relationship between two or more members of a class, requiring clients to invoke one member before the other. This tightly couples the members in the temporal dimension.
{{% /callout %}}

This can be seen if we compare both variations of the `OrderCancellationReportGenerator` object graph from [the previous article](/steven/p/acm/) once more:

{{< highlightEx csharp >}}
// Composes the graph using the Closure Composition Model
IHandler<OrderCancelled> handler =
    new OrderCancellationReportGenerator(
        new OrderRepository(
            {{**}}new ClosureUserContext({{/**}}
                {{**}}queueContext.UserName){{/**}}, //{{annotate}}Injecting runtime data{{/annotate}}
            new SalesDbContext(
                connectionString)));

// Composes the graph using the Ambient Composition Model
IHandler<OrderCancelled> handler =
    new OrderCancellationReportGenerator(
        new OrderRepository(
            {{**}}new AmbientUserContextAdapter(){{/**}},
            new SalesDbContext(
                connectionString)));

// Seting the runtime data before invoking the composed graph
{{**}}AmbientUserContextAdapter.Name.Value ={{/**}} //{{annotate}}Temporal Coupling{{/annotate}}
    {{**}}queueContext.UserName{{/**}};
{{< / highlightEx >}}

While the username is injected into the constructor in the first (closure) object graph, the second (ambient) object graph provides the value after the graph has been constructed. This means that a compile error happens if you forget to supply the username to the first graph, while the second case would result in a runtime exception instead; in other words, the ACM leads to Temporal Coupling.

An important observation is, however, that you as well lose the CCM’s compile-time guarantee when moving from [Pure DI](https://blog.ploeh.dk/2014/06/10/pure-di/) to using a DI Container. This is something that I demonstrated in the [CCM article](/steven/p/ccm/), where I showed the request and initialization of the mutable `ClosureUserContext` class using Autofac. Here’s that example again:

{{< highlightEx csharp >}}
using (ILifetimeScope scope = container.BeginLifetimeScope())
{
    var userContext = scope.Resolve<ClosureUserContext>();

    // Use Property Injection to initialize the graph.
    // Property Injection inherently causes Temporal Coupling
    userContext.UserName = queueContext.UserName; //{{annotate}}Temporal Coupling{{/annotate}}

    var handler = scope.Resolve<IHandler<OrderCancelled>>();

    handler.Handle(queueContext.Message);
}
{{< / highlightEx >}}

In this example, the construction of the `IHandler<OrderCancelled>` service can succeed, even in the absence of some required runtime data. For instance, assume that some components require the request’s start time, but this value was never set, as in the previous example. The call to `handler.Handle` will fail—possibly deep down the call stack, or even just in some specific branches of the code. This is similar to behavior when using the ACM.

When using the CCM, there are some tricks you can apply to move the verification of the availability of this runtime data to an earlier point in time, ideally when calling `Resolve`. A discussion about how to achieve this, however, is outside the scope of this article.

Even though tricks can be applied—thanks to the dynamic nature of DI Containers—you will never be able to completely prevent Temporal Coupling. To make matters worse, when using a DI Container, the resolve will typically be dynamic, meaning that you don’t know which type to resolve at compile time. This means that you will generally have to set _all_ declared runtime data in the scope. As an example, with a DI Container, you wouldn’t explicitly request the `OrderCancellationReportGenerator`, but instead request the handler(s) for a message type that is unknown at compile time:

{{< highlightEx csharp >}}
void ConsumeMessage(object message)
{
    Type handlerType =
        typeof(IHandler<>).MakeGenericType(message.GetType());

    using (var scope = this.container.BeginLifetimeScope())
    {
        dynamic handler = scope.Resolve(handlerType);

        handler.Handle((dynamic)message);
    }
}
{{< / highlightEx >}}

When it comes to Temporal Coupling, the CCM clearly has the upper hand, especially when using Pure DI, although requiring some tricks to improve the verifiability of your object graphs when using a DI Container. With the next topic, however, the ACM is the clear winner.

## Lifetime Management

The ACM adds a very interesting constraint to your code: classes that are part of the constructed object graph should be immutable and are not allowed to capture runtime data. Although the addition of this constraint might seem limiting at first, it does provide you with a simplified mental model.

With the CCM, writing and wiring your application components is a delicate matter, as it is prone to all sorts of easy-to-miss errors. Here are some of the problems you'll likely come across when using the CCM:

* _**Captive Dependencies**_---As discussed in the previous articles, when using the CCM, some of your components need to be stateless because they are injected into singleton consumers, while other classes need to be wired as transient or scoped to prevent their dependencies from becoming [Captive Dependencies](https://blog.ploeh.dk/2014/06/02/captive-dependency/). When object graphs become deep and complex, this can get tricky and confusing. It can get pretty hard to spot these problems with the naked eye—even for a trained DI practitioner like myself. 
* _**Torn Lifestyles**_---When a component is scoped around a web request (or perhaps even scoped around the application’s lifetime), it is easy to accidentally and unknowingly create a second instance of that component within the same logical scope. In that case, the component’s lifestyle is said to be [_torn_](https://simpleinjector.org/diatl). When this happens with stateful components, it can lead to hard-to-track bugs. When working with `DbContext`, for example, having an extra instance will likely cause trouble, because that accidental second instance will rarely be committed, causing a supposed atomic operation to be cut in half.
* _**Ambiguous Lifestyles**_---An accidental coding error in your [Composition Root](https://mng.bz/K1qZ) or a misconfiguration of your DI Container can cause a component to be registered with different, and, therefore, [ambiguous lifestyles](https://simpleinjector.org/diaal). The effect is similar to that of a Torn Lifestyle; too many or too few instances of that component are used at a certain point in time. The resulting misbehavior is often hard to spot.

These are problems you will _not_ encounter when applying one simple rule that the ACM prescribes: All components that are part of the constructed object graphs should be **immutable** and---apart from configuration values---**stateless**.

When all of a graph’s components are stateless, it doesn’t matter how many instances of the component you create. You can never accidentally keep a dependency captive, as its lifetime becomes irrelevant. Similarly, the component’s lifestyle can never become torn or ambiguous for the same reason.

Note that with the ACM, there is still some Lifetime Management left. You will still have to manage the lifetime of runtime data objects, such as `DbContext`. Especially when those objects implement `IDisposable`, deterministic disposal becomes important. By the nature of the ACM, however, those types of stateful objects will _not_ be part of the composed object graphs of your application.

But even with _some_ Lifetime Management left, the ACM greatly reduces the likelihood of falling into many Lifetime Management traps. Another area where this model outperforms its competitor is during code reviews.

## Code reviews

As described previously, the ACM forces a constraint on your code: all components should be immutable and stateless. Although this might feel limiting, it does present you with a simplified mental model—a model where mutability should be frowned on.

Compare that to the CCM, where the lifestyle of a component can depend on one of its indirect dependencies, many layers deep. As you can imagine, with my experience building and maintaining a DI Container, I’ve become pretty good at spotting these issues with the naked eye, even in code bases that use different DI Containers. Nonetheless, I’ve spent a day or more tracking down the reuse of a disposed `DbContext` or other vague lifetime-related issue, on more than one occasion. These kinds of bugs are costly.

But not only does the ACM give a simplified mental model for the developer working on a feature, it drastically simplifies catching these types of mistakes during code reviews. During a code review, the introduction of mutability and statefulness in reviewed code is much easier to spot than the introduction of a Captive Dependency, or any other of the Lifetime Management pitfalls. 

Lifetime Management bugs are often cross-component problems and can span two seemingly unrelated parts of the Composition Root. It can be daunting to spot these errors from within your IDE, let alone during a code review. A code review is typically performed from inside the browser while viewing a pull request. Systems such as GitHub and Bitbucket (obviously) only show the PR’s changes. This makes it hard to spot these errors with the naked eye.

The next section discusses managing and fixing performance problems. Here you’ll see that the ACM again outplays the CCM.

## Performance

A DI Container is a complex tool. There is a lot going on in the background, which can sometimes cause a container to behave in unexpected ways or—at least, for the programmer using it—cause hard-to-track performance problems. And even if it’s not the container’s fault, but our own, tracking down these problems to specific components can be time consuming.

Just as when working in environments with tight memory constraints, caching a stateless object for the duration of the application’s lifetime easily solves any unfortunate performance characteristics that your DI Container might bestow on you. This means that even with [the slowest](http://www.palmmedia.de/blog/2011/8/30/ioc-container-benchmark-performance-comparison) of the slowest DI Containers, resolving object graphs is a one-time cost.

On the other hand, you should take into consideration the possible additional costs that accessing and storing ambient data might bring. With the current versions of .NET and .NET Core, for instance, there is (at the time of writing) a [performance penalty](https://github.com/aspnet/HttpAbstractions/issues/728#issuecomment-254035916) for using ambient data in combination with async/await. Although hopefully something that Microsoft will fix, this penalty to me seems small enough for application developers not to worry about, especially considering the performance improvement that reusing object graphs can provide.

Unfortunately, not all is peaches and cream when it comes to the ACM. This is something I’ll discuss next.

## Swimming against the stream

The CCM is the prevalent composition model. With a few exceptions, [my book](https://manning.com/seemann2) uses this model ubiquitously, though implicitly. Despite the complexity that this model brings, it is the model that your team will likely be most familiar with. Changing from CCM to ACM can, therefore, feel like swimming against the stream.
One area where you will feel a strong CCM current is when building ASP.NET Core applications. The ASP.NET Core framework uses the CCM almost ubiquitously. Many of its stateful components are automatically registered into its DI container, using the Scoped Lifestyle.

Applying the ACM to your ASP.NET Core application will likely complicate object composition. You can’t inject just any framework component directly into your application components; it might very well contain stateful dependencies somewhere in its object graph.

This means that using ASP.NET Core in combination with the ACM pushes you toward hiding framework components behind application-specific abstractions. Custom abstractions allow you to resolve the framework-provided components lazily when an abstraction’s member is invoked (by making use of the [Proxy pattern]( https://en.wikipedia.org/wiki/Proxy_pattern)), as opposed to resolving the component with the rest of the graph. In other words, this combination forces you to adhere more strictly to the [Dependency Inversion Principle](https://en.wikipedia.org/wiki/Dependency_inversion_principle), as it stipulates that “clients [should] own the abstract interfaces (Robert C. Martin, _Agile Principles, Patterns, and Practices in C#_, Pearson, 2007).” As adherence to the SOLID principles isn’t a bad thing to begin with, this object composition "complication" could actually be used to your advantage.

## Conclusion

The conclusion we can draw from the previous analysis is that neither model outperforms the other in every single aspect. This means that you need to decide for yourself what the proper model is for you, based on the constraints of your environment, your application architecture, and knowledge of the developers working with it.

In the next article, however, I will describe my preference and suggest that you to consider it as well.

## Summary

* Most applications use both composition models.
* The choice of which composition model to use starts with a verification of which model is available in your environment. Some environments might not have a mechanism to store ambient data, while others restrict the amount of memory you can produce. When the environment is that restrictive, the remaining list of advantages and disadvantages becomes irrelevant.
* Pure DI in combination with the CCM allows runtime data to be supplied through the constructor. This gives the highest guarantee of availability of data. When you switch from Pure DI to a DI Container, however, Temporal Coupling appears, with both the CCM and the ACM.
* The ACM greatly simplifies Lifetime Management and prevents many DI pitfalls that will torment you when using the CCM.
* The ACM provides you with a simplified mental model that makes it much easier to spot DI-related bugs, both during development and during code reviews.
* Due to its statelessness, the ACM allows object graphs to be reused and become singletons. This reduces performance problems that DI Containers can cause.
* The CCM is the prevalent composition model. Using a different composition model can feel like swimming against the stream. Even though the ACM presents a simplified mental model, you might still get resistance from developers on your team or suffer incompatibility from the framework you use.

## Comments